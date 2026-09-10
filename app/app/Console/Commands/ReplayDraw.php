<?php

namespace App\Console\Commands;

use App\Enums\DrawSystem;
use App\Models\Game;
use App\Models\Player;
use App\Models\Round;
use App\Services\DrawService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Herloot een gespeeld seizoen op de echte aanwezigheden en meet wat eruit komt.
 *
 * Bestaat omdat elke uitspraak over een lotingsysteem anders een anekdote is. De
 * cijfers in PLAN.md fase 16 zijn hiermee gemeten, en een volgende wijziging aan
 * `DrawService` hoort ze opnieuw te kunnen produceren.
 *
 * Werkwijze: in een transactie die altijd terugdraait worden de wedstrijden van het
 * seizoen weggegooid en speeldag per speeldag opnieuw geloot, met dezelfde
 * aanwezigheidslijsten als toen. De wedstrijden gaan met de query builder de databank
 * in en niet via het model, want `GameObserver` zou per wedstrijd het hele seizoen
 * herrekenen -- dat duurt minuten en verandert niets aan de loting.
 *
 * Wat het *niet* doet: de gemiddelden herrekenen. De sterktegroepen sorteren dus op de
 * gemiddelden zoals ze werkelijk gelopen zijn. Dat is precies wat je wil vergelijken:
 * dezelfde spelers, dezelfde avonden, enkel een andere indelingsregel.
 */
class ReplayDraw extends Command
{
    protected $signature = 'draw:replay
        {season : id of een deel van de naam van het seizoen}
        {--system= : sterktegroepen|wisselend, standaard wat op het seizoen staat}
        {--runs=1 : aantal herhalingen; de loting is willekeurig, dus meer runs geeft een stabieler cijfer}';

    protected $description = 'Herloot een gespeeld seizoen en meet spreiding en handicap';

    /** Waar de score-invoer op stukloopt: het sterke duo begint dan op -5 of lager. */
    private const STEEP_HANDICAP = 10;

    public function handle(DrawService $drawService): int
    {
        $season = $this->season();
        $system = $this->system() ?? $season->draw_system;
        $runs = max(1, (int) $this->option('runs'));

        $this->components->info(sprintf(
            'Seizoen %s, %s, %d run(s)',
            $season->name,
            $system->getLabel(),
            $runs
        ));

        $bonuses = Player::all()->mapWithKeys(
            fn (Player $player): array => [$player->id => $player->bonus_points]
        )->all();

        $measurements = [];
        $started = microtime(true);

        for ($run = 0; $run < $runs; $run++) {
            $measurements[] = $this->measure($this->replay($season->id, $system, $drawService), $bonuses);
        }

        $this->report($measurements, microtime(true) - $started, $runs);

        return self::SUCCESS;
    }

    /**
     * Loot elke speeldag van het seizoen opnieuw en geef de samengestelde viertallen
     * terug. De databank blijft ongemoeid: de transactie draait altijd terug.
     *
     * @return list<list<int>>
     */
    private function replay(int $seasonId, DrawSystem $system, DrawService $drawService): array
    {
        $games = [];

        // Openen en altijd terugdraaien, ook als de loting onderweg klapt. Een
        // herloting mag nooit echte wedstrijden overschrijven.
        DB::beginTransaction();

        try {
            DB::table('seasons')->where('id', $seasonId)->update(['draw_system' => $system->value]);

            $roundIds = DB::table('rounds')->where('season_id', $seasonId)->pluck('id');
            DB::table('games')->whereIn('round_id', $roundIds)->delete();
            DB::table('player_round_statistics')
                ->whereIn('round_id', $roundIds)
                ->update(['is_drawn_out' => false]);

            $rounds = Round::where('season_id', $seasonId)->orderBy('number')->with('season')->get();

            foreach ($rounds as $round) {
                foreach ($drawService->draw($round)['games'] as $playerIds) {
                    // Rechtstreeks in de databank: via het model zou GameObserver per
                    // wedstrijd het hele seizoen herrekenen.
                    DB::table('games')->insert([
                        'round_id' => $round->id,
                        'player1_id' => $playerIds[0],
                        'player2_id' => $playerIds[1],
                        'player3_id' => $playerIds[2],
                        'player4_id' => $playerIds[3],
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);

                    $games[] = $playerIds;
                }
            }
        } finally {
            DB::rollBack();
        }

        return $games;
    }

    /**
     * De cijfers waarmee twee lotingen te vergelijken zijn.
     *
     * Spreiding staat naast haar plafond, want "26,5 verschillende tegenstanders" zegt
     * op zichzelf niets: wie zes keer speelt kan er hoogstens achttien zien. De
     * handicap telt mee omdat de tie-break erop stuurt, en de staart (H >= 10) is de
     * bordstand die de score-invoer niet kan opslaan.
     *
     * @param  list<list<int>>  $games
     * @param  array<int, int>  $bonuses
     * @return array<string, float>
     */
    private function measure(array $games, array $bonuses): array
    {
        $met = [];
        $played = [];

        foreach ($games as $playerIds) {
            foreach ($playerIds as $player) {
                $played[$player] = ($played[$player] ?? 0) + 1;

                foreach ($playerIds as $other) {
                    if ($player !== $other) {
                        $met[$player][$other] = ($met[$player][$other] ?? 0) + 1;
                    }
                }
            }
        }

        if ($met === []) {
            throw new RuntimeException('Geen enkele wedstrijd geloot; heeft dit seizoen aanwezigheden?');
        }

        $distinct = 0;
        $highestRepeat = 0;
        $repeatedPairs = 0;

        foreach ($met as $opponents) {
            $distinct += count($opponents);
            $highestRepeat = max($highestRepeat, ...array_values($opponents));
            $repeatedPairs += count(array_filter($opponents, fn (int $times): bool => $times >= 3));
        }

        $handicaps = $this->handicaps($games, $bonuses);
        sort($handicaps);

        return [
            'spelers' => (float) count($met),
            'wedstrijden' => (float) count($games),
            'verschillende' => $distinct / count($met),
            'plafond' => 3 * array_sum($played) / count($played),
            'hoogste herhaling' => (float) $highestRepeat,
            'koppels vanaf 3x (%)' => 100 * $repeatedPairs / $distinct,
            'H gemiddeld' => array_sum($handicaps) / count($handicaps),
            'H p95' => (float) $handicaps[(int) floor(0.95 * (count($handicaps) - 1))],
            'H hoogste' => (float) max($handicaps),
            'H vanaf 10 (%)' => 100 * count(array_filter(
                $handicaps,
                fn (int $handicap): bool => $handicap >= self::STEEP_HANDICAP
            )) / count($handicaps),
        ];
    }

    /**
     * De handicap van elke set van elke wedstrijd: het verschil tussen de bonussommen
     * van de twee duo's, met de rotatie uit Game::LINE_UPS.
     *
     * @param  list<list<int>>  $games
     * @param  array<int, int>  $bonuses
     * @return list<int>
     */
    private function handicaps(array $games, array $bonuses): array
    {
        $handicaps = [];

        foreach ($games as $playerIds) {
            foreach (Game::LINE_UPS as [$homeSlots, $awaySlots]) {
                $home = $bonuses[$playerIds[$homeSlots[0] - 1]] + $bonuses[$playerIds[$homeSlots[1] - 1]];
                $away = $bonuses[$playerIds[$awaySlots[0] - 1]] + $bonuses[$playerIds[$awaySlots[1] - 1]];

                $handicaps[] = abs($home - $away);
            }
        }

        return $handicaps;
    }

    /**
     * Print elke maat, gemiddeld over de runs. Bij meer dan één run staat het bereik
     * erbij: is dat breed, dan zegt het gemiddelde weinig.
     *
     * @param  list<array<string, float>>  $measurements
     */
    private function report(array $measurements, float $seconds, int $runs): void
    {
        $rows = [];

        foreach (array_keys($measurements[0]) as $measure) {
            $values = array_column($measurements, $measure);

            $rows[] = [
                $measure,
                number_format(array_sum($values) / count($values), 2),
                $runs > 1 && min($values) !== max($values)
                    ? number_format(min($values), 2).' - '.number_format(max($values), 2)
                    : '',
            ];
        }

        $ceilings = array_column($measurements, 'plafond');
        $spreads = array_column($measurements, 'verschillende');
        $rows[] = [
            '% van plafond',
            number_format(100 * array_sum($spreads) / array_sum($ceilings), 1),
            '',
        ];

        $this->table(['maat', 'gemiddeld', $runs > 1 ? 'bereik' : ''], $rows);
        $this->line(sprintf('  %d run(s) in %.1f s', $runs, $seconds));
    }

    private function season(): object
    {
        $argument = $this->argument('season');

        $season = DB::table('seasons')
            ->when(is_numeric($argument), fn ($query) => $query->where('id', (int) $argument))
            ->when(! is_numeric($argument), fn ($query) => $query->where('name', 'like', '%'.$argument.'%'))
            ->first(['id', 'name', 'draw_system']);

        if ($season === null) {
            throw new RuntimeException(sprintf('Geen seizoen gevonden voor "%s".', $argument));
        }

        $season->draw_system = DrawSystem::from($season->draw_system);

        return $season;
    }

    private function system(): ?DrawSystem
    {
        return match ($this->option('system')) {
            null, '' => null,
            'sterktegroepen', 'strength_groups' => DrawSystem::StrengthGroups,
            'wisselend', 'varying_opponents' => DrawSystem::VaryingOpponents,
            default => throw new RuntimeException('Kies --system=sterktegroepen of --system=wisselend.'),
        };
    }
}
