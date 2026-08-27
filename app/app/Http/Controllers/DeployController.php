<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/*
 * Uitvoerpad voor GitHub Actions. De hosting heeft geen SSH, dus wat lokaal een
 * artisan-commando is, gebeurt hier over HTTP achter een Bearer-token.
 *
 * Zonder DEPLOY_TOKEN in de .env geven alle acties 404 — niet 403, want dat zou
 * verklappen dat er iets te vinden is.
 */
class DeployController extends Controller
{
    /** De enige artisan-commando's die via HTTP mogen draaien. */
    private const TASKS = [
        'migrate' => ['migrate', ['--force' => true]],
        'optimize' => ['optimize', []],
        'clear' => ['optimize:clear', []],
    ];

    /** Prefix van de veiligheidskopieën die een reset achterlaat. */
    private const BACKUP_PREFIX = 'bak_';

    public function run(Request $request, string $task): JsonResponse
    {
        $this->authorizeToken($request);

        [$command, $options] = self::TASKS[$task];

        try {
            $exit = Artisan::call($command, $options);
        } catch (Throwable $e) {
            return $this->failure($task, $e);
        }

        return response()->json([
            'task' => $task,
            'php' => PHP_VERSION,
            'exit_code' => $exit,
            'output' => Artisan::output(),
        ], $exit === 0 ? 200 : 500);
    }

    /*
     * Zet de databank terug naar de snapshot die bij de cutover-export gemaakt is.
     * Drie remmen, onafhankelijk van elkaar:
     *   1. de workflow eist dat je letterlijk "RESET" typt;
     *   2. INTRACLUB_ALLOW_RESET moet aan staan;
     *   3. het snapshotbestand moet op de server staan (verwijderen = definitief
     *      dood, ook als de configuratiecache nog oude env-waarden bevat).
     * En voor de zekerheid: elke tabel wordt eerst binnen de databank gekopieerd.
     */
    public function reset(Request $request): JsonResponse
    {
        $this->authorizeToken($request);

        abort_unless(config('deploy.allow_reset'), 404);

        $snapshot = storage_path(config('deploy.snapshot'));

        abort_unless(is_file($snapshot), 409, 'Snapshot staat niet op de server: '.config('deploy.snapshot'));

        set_time_limit(0);
        ignore_user_abort(true);

        try {
            $tables = $this->liveTables();
            $prefix = self::BACKUP_PREFIX.now()->format('ymdHis').'_';

            $this->backup($tables, $prefix);
            $dropped = $this->pruneBackups($prefix);
            $this->drop($tables);

            $statements = $this->import($snapshot);

            Artisan::call('migrate', ['--force' => true]);
            $migrations = Artisan::output();

            Artisan::call('optimize');
        } catch (Throwable $e) {
            return $this->failure('reset', $e);
        }

        return response()->json([
            'task' => 'reset',
            'php' => PHP_VERSION,
            'backup_prefix' => $prefix,
            'backed_up_tables' => count($tables),
            'pruned_backup_sets' => $dropped,
            'imported_statements' => $statements,
            'migrations' => $migrations,
        ]);
    }

    /**
     * Een gefaalde taak moet zichzelf verklaren: er is geen SSH om in de logs te
     * gaan kijken, en APP_DEBUG staat (terecht) uit. Wie het token heeft, mag de
     * foutmelding zien.
     */
    private function failure(string $task, Throwable $e): JsonResponse
    {
        return response()->json([
            'task' => $task,
            'php' => PHP_VERSION,
            'error' => $e::class.': '.$e->getMessage(),
            'at' => $e->getFile().':'.$e->getLine(),
            'output' => rescue(fn (): string => Artisan::output(), '', report: false),
        ], 500);
    }

    private function authorizeToken(Request $request): void
    {
        $token = config('deploy.token');

        abort_if(blank($token), 404);
        abort_unless(hash_equals((string) $token, (string) $request->bearerToken()), 404);
    }

    /**
     * Kale tabelnamen van uitsluitend de eigen databank.
     *
     * Twee valkuilen die dit vermijdt: `getTableListing()` geeft standaard
     * schema-gekwalificeerde namen (`db.tabel`), wat in backticks een ongeldige
     * tabelnaam oplevert; en zonder schema-argument levert de MySQL-grammar de
     * tabellen van élk niet-systeemschema op de server — op shared hosting dus
     * ook andere databanken van hetzelfde account.
     *
     * @return list<string>
     */
    private function tableNames(): array
    {
        $tables = array_column(Schema::getTables(DB::getDatabaseName()), 'name');

        abort_if($tables === [], 500, 'Geen tabellen gevonden in '.DB::getDatabaseName().'.');

        return $tables;
    }

    /**
     * Alle tabellen die niet zelf een veiligheidskopie zijn.
     *
     * @return list<string>
     */
    private function liveTables(): array
    {
        return array_values(array_filter(
            $this->tableNames(),
            fn (string $table): bool => ! str_starts_with($table, self::BACKUP_PREFIX),
        ));
    }

    /*
     * Kopie binnen dezelfde databank: goedkoop (de data is enkele MB) en met de
     * hand herstelbaar via phpMyAdmin. `CREATE TABLE ... LIKE` neemt indexen mee,
     * geen foreign keys — voor een kopie is dat precies genoeg.
     */
    private function backup(array $tables, string $prefix): void
    {
        foreach ($tables as $table) {
            $copy = $prefix.$table;

            if (strlen($copy) > 64) {
                continue; // MySQL-limiet; zo'n tabelnaam bestaat hier niet
            }

            DB::statement("CREATE TABLE `{$copy}` LIKE `{$table}`");
            DB::statement("INSERT INTO `{$copy}` SELECT * FROM `{$table}`");
        }
    }

    /** Houdt enkel de nieuwste reeksen over; geeft terug hoeveel reeksen weg zijn. */
    private function pruneBackups(string $current): int
    {
        $sets = [];

        foreach ($this->tableNames() as $table) {
            if (! str_starts_with($table, self::BACKUP_PREFIX)) {
                continue;
            }

            // bak_<ymdHis>_<tabel> → de reeks is alles tot en met het tijdstip
            $sets[substr($table, 0, strlen($current))][] = $table;
        }

        krsort($sets);

        $stale = array_slice($sets, max(1, config('deploy.backup_sets_kept')), preserve_keys: true);

        foreach ($stale as $tables) {
            $this->drop($tables);
        }

        return count($stale);
    }

    private function drop(array $tables): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            foreach ($tables as $table) {
                DB::statement("DROP TABLE IF EXISTS `{$table}`");
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    /*
     * Streamt de snapshot statement per statement. mysqldump escapet nieuwe
     * regels binnen waarden, dus een regel die op `;` eindigt sluit altijd een
     * statement af — regelgewijs lezen is hier veilig én houdt het geheugen laag.
     */
    private function import(string $path): int
    {
        $stream = str_ends_with($path, '.gz') ? 'compress.zlib://'.$path : $path;
        $handle = fopen($stream, 'rb');

        abort_if($handle === false, 500, 'Snapshot kon niet gelezen worden.');

        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        $buffer = '';
        $count = 0;

        try {
            while (($line = fgets($handle)) !== false) {
                $trimmed = rtrim($line);

                if ($buffer === '' && ($trimmed === '' || str_starts_with($trimmed, '--'))) {
                    continue;
                }

                $buffer .= $line;

                if (! str_ends_with($trimmed, ';')) {
                    continue;
                }

                DB::unprepared($buffer);
                $buffer = '';
                $count++;
            }
        } finally {
            DB::statement('SET FOREIGN_KEY_CHECKS=1');
            fclose($handle);
        }

        return $count;
    }
}
