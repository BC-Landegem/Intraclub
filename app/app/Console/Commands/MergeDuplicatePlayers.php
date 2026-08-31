<?php

namespace App\Console\Commands;

use App\Models\Season;
use App\Services\SeasonCalculator;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Voegt spelers samen die tweemaal in `players` beland zijn.
 *
 * Welke id's dezelfde persoon zijn, staat in database/legacy/player-map-overrides.php —
 * hetzelfde bestand dat de archiefmapping stuurt. De speler met de meeste wedstrijden
 * blijft; de ander verdwijnt nadat zijn wedstrijden en aanwezigheden overgezet zijn.
 *
 * Draai dit ná `intraclub:verify-calculation`: die vergelijkt met de opgeslagen
 * legacy-waarden, en na een samenvoeging wijken die voor de samengevoegde speler
 * terecht af — hij heeft nu alle wedstrijden in plaats van een deel.
 */
class MergeDuplicatePlayers extends Command
{
    protected $signature = 'intraclub:merge-duplicates
        {--force : Sla de bevestiging over}
        {--dry-run : Toon wat er zou gebeuren, zonder iets te wijzigen}';

    protected $description = 'Voegt dubbel aangemaakte spelers samen en herberekent de betrokken seizoenen.';

    public function handle(SeasonCalculator $calculator): int
    {
        $paren = $this->paren();

        if ($paren === []) {
            $this->info('Niets samen te voegen: alle dubbels uit player-map-overrides.php zijn al opgeruimd.');

            return self::SUCCESS;
        }

        $this->toonPlan($paren);

        if ($this->option('dry-run')) {
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Doorgaan? Dit verwijdert de dubbele spelers definitief.')) {
            return self::FAILURE;
        }

        $seizoenen = [];

        foreach ($paren as $paar) {
            $seizoenen = [...$seizoenen, ...$this->voegSamen($paar['dubbel'], $paar['blijft'])];
        }

        $seizoenen = array_values(array_unique($seizoenen));
        sort($seizoenen);

        foreach ($seizoenen as $seizoenId) {
            $seizoen = Season::find($seizoenId);
            if ($seizoen === null) {
                continue;
            }
            $calculator->calculate($seizoen);
            $this->line("Herberekend: {$seizoen->name}");
        }

        $this->newLine();
        $this->info(sprintf('%d speler(s) samengevoegd, %d seizoen(en) herberekend.', count($paren), count($seizoenen)));

        return self::SUCCESS;
    }

    /**
     * De paren die nog echt bestaan, met de speler die blijft. Wie de meeste wedstrijden
     * heeft wint; wijkt dat af van de richting in het overridebestand, dan stopt het hier
     * liever dan de verkeerde fiche te bewaren.
     *
     * @return list<array{dubbel: int, blijft: int, naam: string, games_dubbel: int, games_blijft: int}>
     */
    private function paren(): array
    {
        $overrides = require database_path('legacy/player-map-overrides.php');
        $paren = [];

        foreach ($overrides['player_dubbels'] as $dubbelId => $blijftId) {
            $dubbel = DB::table('players')->find($dubbelId);
            $blijft = DB::table('players')->find($blijftId);

            if ($dubbel === null) {
                continue; // Al samengevoegd bij een eerdere run.
            }
            if ($blijft === null) {
                $this->warn("Speler {$blijftId} bestaat niet; paar {$dubbelId} → {$blijftId} overgeslagen.");

                continue;
            }

            $gamesDubbel = $this->aantalGames($dubbelId);
            $gamesBlijft = $this->aantalGames($blijftId);

            if ($gamesDubbel > $gamesBlijft) {
                $this->error(sprintf(
                    'Speler %d heeft méér wedstrijden (%d) dan %d (%d). Draai de richting om in player-map-overrides.php.',
                    $dubbelId,
                    $gamesDubbel,
                    $blijftId,
                    $gamesBlijft,
                ));

                continue;
            }

            $paren[] = [
                'dubbel' => $dubbelId,
                'blijft' => $blijftId,
                'naam' => "{$blijft->first_name} {$blijft->last_name}",
                'games_dubbel' => $gamesDubbel,
                'games_blijft' => $gamesBlijft,
            ];
        }

        return $paren;
    }

    /** @param list<array{dubbel: int, blijft: int, naam: string, games_dubbel: int, games_blijft: int}> $paren */
    private function toonPlan(array $paren): void
    {
        $this->table(
            ['Speler', 'Blijft', 'Wedstrijden', 'Verdwijnt', 'Wedstrijden'],
            array_map(fn (array $p): array => [
                $p['naam'],
                "id {$p['blijft']}",
                $p['games_blijft'],
                "id {$p['dubbel']}",
                $p['games_dubbel'],
            ], $paren),
        );
    }

    /**
     * Zet alles van de dubbele speler over en verwijder hem.
     *
     * @return list<int> de seizoenen die daardoor herberekend moeten worden
     */
    private function voegSamen(int $dubbelId, int $blijftId): array
    {
        return DB::transaction(function () use ($dubbelId, $blijftId): array {
            $seizoenen = DB::table('player_season_statistics')
                ->where('player_id', $dubbelId)
                ->pluck('season_id')
                ->all();

            $seizoenen = [...$seizoenen, ...DB::table('rounds')
                ->join('games', 'games.round_id', '=', 'rounds.id')
                ->where(fn ($query) => $query
                    ->orWhere('games.player1_id', $dubbelId)
                    ->orWhere('games.player2_id', $dubbelId)
                    ->orWhere('games.player3_id', $dubbelId)
                    ->orWhere('games.player4_id', $dubbelId))
                ->distinct()
                ->pluck('rounds.season_id')
                ->all()];

            foreach (['player1_id', 'player2_id', 'player3_id', 'player4_id'] as $kolom) {
                DB::table('games')->where($kolom, $dubbelId)->update([$kolom => $blijftId]);
            }

            if ($this->neemLidmaatschapOver($dubbelId, $blijftId)) {
                // De ledenstatus bepaalt in welke klassementen de speler meetelt, en dus ook
                // de plaats van iedereen onder hem. Elk seizoen waarin hij een fiche heeft
                // moet daarom opnieuw gerekend worden, niet enkel wat de merge aanraakt.
                $seizoenen = [...$seizoenen, ...DB::table('player_season_statistics')
                    ->where('player_id', $blijftId)
                    ->pluck('season_id')
                    ->all()];
            }

            $this->voegRondestatistiekenSamen($dubbelId, $blijftId);

            $this->voegSeizoenstatistiekenSamen($dubbelId, $blijftId);

            DB::table('players')->where('id', $dubbelId)->delete();

            return array_values(array_unique($seizoenen));
        });
    }

    /**
     * Lid zijn is een ingevoerd feit dat maar op één fiche hoeft te staan: wie terugkeert
     * wordt soms opnieuw aangemaakt, en dan draagt net de fiche die verdwijnt het vinkje.
     * Wie onder één van beide fiches lid is, is lid.
     *
     * Dit gebeurt vóór de statistieken, want de ledenstatus bepaalt daar of de rij nog leeft.
     *
     * @return bool of het vinkje verhuisd is
     */
    private function neemLidmaatschapOver(int $dubbelId, int $blijftId): bool
    {
        $dubbelIsLid = (bool) DB::table('players')->where('id', $dubbelId)->value('is_member');
        $blijftIsLid = (bool) DB::table('players')->where('id', $blijftId)->value('is_member');

        if (! $dubbelIsLid || $blijftIsLid) {
            return false;
        }

        DB::table('players')->where('id', $blijftId)->update(['is_member' => true]);

        $this->line("Speler {$blijftId} is lid: het vinkje stond op de dubbel ({$dubbelId}).");

        return true;
    }

    /**
     * Aanwezig en uitgeloot zijn ingevoerde feiten, geen afgeleide: waar beide fiches een
     * rij hebben voor dezelfde speeldag, blijft de speler aanwezig of uitgeloot als hij dat
     * onder één van beide was.
     */
    private function voegRondestatistiekenSamen(int $dubbelId, int $blijftId): void
    {
        $bestaand = DB::table('player_round_statistics')
            ->where('player_id', $blijftId)
            ->pluck('id', 'round_id');

        foreach (DB::table('player_round_statistics')->where('player_id', $dubbelId)->get() as $rij) {
            $doelId = $bestaand[$rij->round_id] ?? null;

            if ($doelId === null) {
                DB::table('player_round_statistics')->where('id', $rij->id)->update(['player_id' => $blijftId]);

                continue;
            }

            $doel = DB::table('player_round_statistics')->find($doelId);
            DB::table('player_round_statistics')->where('id', $doelId)->update([
                'is_present' => (bool) $doel->is_present || (bool) $rij->is_present,
                'is_drawn_out' => (bool) $doel->is_drawn_out || (bool) $rij->is_drawn_out,
            ]);
            DB::table('player_round_statistics')->where('id', $rij->id)->delete();
        }
    }

    /**
     * De seizoenstellers zijn afgeleid uit de wedstrijden, maar de herberekening raakt enkel
     * spelers met `is_member`. Voor een niet-lid staan de tellers stil op wat de bron laatst
     * berekend heeft, en dan is wegwerpen van de rij van de dubbel definitief verlies. Daarom
     * worden de tellers altijd opgeteld: de wedstrijden van beide fiches zijn disjunct.
     *
     * De basispunten zijn geen afgeleide maar een invoer, en de juiste keuze hangt af van of
     * de rij nog leeft. Bij een lid herberekent alles zich toch: dan tellen de basispunten van
     * de blijvende fiche, want die zijn afgeleid uit zijn eindstand van vorig seizoen — de
     * dubbel begon als nieuwkomer op 19,0000 en dat is niet zijn plaats. Bij een niet-lid is de
     * rij bevroren en bewaren we ze zoals de bron ze toonde: met de basispunten van de fiche
     * waaronder dat seizoen effectief gespeeld en gerekend is.
     */
    private function voegSeizoenstatistiekenSamen(int $dubbelId, int $blijftId): void
    {
        $tellers = ['sets_played', 'sets_won', 'points_played', 'points_won', 'rounds_present', 'games_played'];

        $isLid = (bool) DB::table('players')->where('id', $blijftId)->value('is_member');

        $bestaand = DB::table('player_season_statistics')->where('player_id', $blijftId)->get()->keyBy('season_id');

        foreach (DB::table('player_season_statistics')->where('player_id', $dubbelId)->get() as $rij) {
            $doel = $bestaand[$rij->season_id] ?? null;

            if ($doel === null) {
                DB::table('player_season_statistics')->where('id', $rij->id)->update(['player_id' => $blijftId]);

                continue;
            }

            if ($doel->games_played > 0 && $rij->games_played > 0) {
                $this->warn(sprintf(
                    'Seizoen %d: beide fiches van speler %d speelden (%d en %d wedstrijden). Kijk basispunten en aanwezigheden na.',
                    $rij->season_id,
                    $blijftId,
                    $doel->games_played,
                    $rij->games_played,
                ));
            }

            $waarden = [];
            foreach ($tellers as $teller) {
                $waarden[$teller] = $doel->$teller + $rij->$teller;
            }

            if (! $isLid && $rij->games_played > $doel->games_played) {
                $waarden['base_points'] = $rij->base_points;
            }

            DB::table('player_season_statistics')->where('id', $doel->id)->update($waarden);
            DB::table('player_season_statistics')->where('id', $rij->id)->delete();
        }
    }

    private function aantalGames(int $playerId): int
    {
        return DB::table('games')
            ->where(fn ($query) => $query
                ->orWhere('player1_id', $playerId)
                ->orWhere('player2_id', $playerId)
                ->orWhere('player3_id', $playerId)
                ->orWhere('player4_id', $playerId))
            ->count();
    }
}
