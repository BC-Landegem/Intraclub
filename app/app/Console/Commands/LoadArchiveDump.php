<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Laadt de oudste intraclub-generaties uit de volledige sitedump in de connectie "archive".
 *
 * De dump van bclandegem.be bevat naast Joomla ook `comp_*` (2009-2013) en `intra_*`
 * (2013-2023). Alleen die tabellen worden overgenomen; de rest van de site slaan we over.
 * Herhaalbaar: de dump bevat DROP TABLE-statements, dus een tweede run vervangt de data.
 */
class LoadArchiveDump extends Command
{
    protected $signature = 'intraclub:load-archive-dump
        {file : Pad naar de volledige sitedump (bclandegem_database_*.sql)}';

    protected $description = 'Laadt de tabellen comp_* en intra_* uit de volledige sitedump in de archiefdatabank.';

    /** Alleen tabellen met deze voorvoegsels nemen we over. */
    private const PREFIXEN = ['comp_', 'intra_'];

    /**
     * Statements die naar een tabel verwijzen die ons interesseert. LOCK TABLES staat er
     * bewust niet bij: dat zet de sessie in vergrendelde modus, waarna elke tabel die niet
     * mee vergrendeld is onbereikbaar wordt. Voor een eenmalige import is het overbodig.
     */
    private const TABEL_PATROON = '/^(?:DROP TABLE(?: IF EXISTS)?|CREATE TABLE(?: IF NOT EXISTS)?|INSERT INTO|REPLACE INTO|ALTER TABLE)\s+`([^`]+)`/i';

    public function handle(): int
    {
        $bestand = $this->argument('file');

        if (! is_readable($bestand)) {
            $this->error("Kan {$bestand} niet lezen.");

            return self::FAILURE;
        }

        $this->maakDatabankAan();

        $verbinding = DB::connection('archive');
        $this->info(sprintf('Doel: %s', $verbinding->getDatabaseName()));

        // De oude data haalt de huidige strictheid niet: `klassement` bevat een lege
        // waarde die niet in de enum staat, en de tabellen staan alfabetisch in de dump
        // waardoor foreign keys naar nog niet bestaande tabellen wijzen.
        $verbinding->unprepared("SET SESSION sql_mode=''");
        $verbinding->unprepared('SET FOREIGN_KEY_CHECKS=0');

        try {
            [$uitgevoerd, $tabellen] = $this->voerDumpUit($bestand, $verbinding);
        } finally {
            $verbinding->unprepared('SET FOREIGN_KEY_CHECKS=1');
        }

        $this->newLine();
        $this->info(sprintf('%d statements uitgevoerd over %d tabellen:', $uitgevoerd, count($tabellen)));

        sort($tabellen);
        foreach ($tabellen as $tabel) {
            $this->line(sprintf('  %-28s %6d rijen', $tabel, $verbinding->table($tabel)->count()));
        }

        return self::SUCCESS;
    }

    /**
     * De archiefdatabank hoeft nog niet te bestaan: we maken ze aan via een verbinding
     * met de server zelf, zodat dit commando geen handwerk vooraf vraagt.
     */
    private function maakDatabankAan(): void
    {
        $config = Config::get('database.connections.archive');
        $naam = $config['database'];

        if (! preg_match('/^[A-Za-z0-9_]+$/', $naam)) {
            throw new RuntimeException("Ongeldige databanknaam: {$naam}");
        }

        Config::set('database.connections.archive_server', [...$config, 'database' => null]);

        DB::connection('archive_server')->unprepared(
            "CREATE DATABASE IF NOT EXISTS `{$naam}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
        );
        DB::purge('archive_server');
    }

    /**
     * Leest de dump regel per regel en voert enkel de statements uit die over comp_- of
     * intra_-tabellen gaan. Statements worden per regel opgebouwd tot er één eindigt op
     * een puntkomma; mysqldump schrijft elke INSERT op één regel, dus dat volstaat.
     *
     * @return array{0: int, 1: list<string>}
     */
    private function voerDumpUit(string $bestand, ConnectionInterface $verbinding): array
    {
        $handle = fopen($bestand, 'r');
        if ($handle === false) {
            throw new RuntimeException("Kan {$bestand} niet openen.");
        }

        $buffer = '';
        $uitgevoerd = 0;
        $tabellen = [];
        $balk = $this->output->createProgressBar((int) ceil(filesize($bestand) / 1_048_576));
        $balk->setFormat(' %current%/%max% MB [%bar%] %elapsed%');
        $gelezen = 0;
        $volgendeStap = 1_048_576;

        try {
            while (($regel = fgets($handle)) !== false) {
                $gelezen += strlen($regel);
                if ($gelezen >= $volgendeStap) {
                    $balk->advance();
                    $volgendeStap += 1_048_576;
                }

                $getrimd = rtrim($regel);
                if ($getrimd === '' || str_starts_with($getrimd, '--') || str_starts_with($getrimd, '/*')) {
                    continue;
                }

                $buffer .= ($buffer === '' ? '' : "\n").$getrimd;
                if (! str_ends_with($getrimd, ';')) {
                    continue;
                }

                $statement = $buffer;
                $buffer = '';

                if (! preg_match(self::TABEL_PATROON, $statement, $treffer)) {
                    continue;
                }
                $tabel = $treffer[1];
                if (! $this->isGewenst($tabel)) {
                    continue;
                }

                $verbinding->unprepared($statement);
                $uitgevoerd++;

                if (! in_array($tabel, $tabellen, true) && str_starts_with(strtoupper($statement), 'CREATE TABLE')) {
                    $tabellen[] = $tabel;
                }
            }
        } finally {
            fclose($handle);
            $balk->finish();
        }

        return [$uitgevoerd, $tabellen];
    }

    private function isGewenst(string $tabel): bool
    {
        foreach (self::PREFIXEN as $prefix) {
            if (str_starts_with($tabel, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
