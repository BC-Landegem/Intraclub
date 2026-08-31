<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Player;
use App\Models\Round;
use App\Models\Season;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Clubrecords over de seizoenen in het huidige format.
 *
 * De jaargangen 2009-2023 doen hier niet mee: die speelden met vaste teams in
 * best-of-3, dus een dagscore van toen is niet hetzelfde getal als een van nu.
 * Die veertien seizoenen hebben hun eigen eindstanden onder /api/archive.
 *
 * Twee lijsten voor "beste prestatie", bewust apart: `best_days` is één avond,
 * `best_seasons` een heel seizoen. Een dagscore van 15,00 betekent "alle drie de
 * sets gewonnen" en komt vaak voor, dus die lijst breekt gelijke scores op
 * puntensaldo — anders is het geen rangschikking maar een toevallige volgorde.
 *
 * Over de rank: deze service leest `player_round_statistics.rank` niet, maar
 * berekent de plaats per speeldag opnieuw over iedereen die die speeldag een
 * gemiddelde had. De opgeslagen rank is bevroren op wie er bij de berekening lid
 * was, en voor de afgesloten seizoenen is dat het ledenbestand van vandaag: in
 * 2023-2024 dekt hij 60 van de 96 spelers die meededen. Een sprong op een stand
 * van 60 is een ander getal dan een sprong op een stand van 96, dus staat
 * `players_ranked` bij elke rij die over een plaats gaat.
 */
class Records
{
    /** @var Collection<int, Player> */
    private Collection $players;

    /** @var Collection<int, Season> */
    private Collection $seasons;

    /**
     * @param  list<int>  $seasonIds
     * @return array<string, list<array<string, mixed>>>
     */
    public function all(array $seasonIds, int $limit): array
    {
        // Eén keer ophalen in plaats van per rij: een recordlijst raakt al snel
        // honderden spelers aan, ook wie ondertussen gestopt is.
        $this->players = Player::query()->get()->keyBy('id');
        $this->seasons = Season::query()->whereIn('id', $seasonIds)->get()->keyBy('id');

        $rounds = Round::query()->whereIn('season_id', $seasonIds)->orderBy('number')->get();
        $games = $this->games($seasonIds);
        $presence = $this->presencePerRound($seasonIds);
        ['ranks' => $ranks, 'averages' => $averages] = $this->standingsPerRound($seasonIds);

        return [
            'best_days' => $this->bestDays($games, $limit),
            'best_seasons' => $this->bestSeasons($rounds, $ranks, $averages, $limit),
            'biggest_climbs' => $this->biggestClimbs($rounds, $ranks, $averages, $presence, $limit),
            'longest_streaks' => $this->longestStreaks($rounds, $presence, $limit),
            'most_played_duos' => $this->mostPlayedDuos($games, $limit),
        ];
    }

    /* ---------------------------------------------------------------- de lijsten */

    /**
     * De beste avonden: hoogste dagscore, bij gelijke score het grootste
     * puntensaldo. Driemaal 15-0 staat dus boven driemaal 15-13, ook al is de
     * dagscore van beiden 15,00.
     *
     * @param  Collection<int, Game>  $games
     * @return list<array<string, mixed>>
     */
    private function bestDays(Collection $games, int $limit): array
    {
        $rijen = [];

        foreach ($games as $game) {
            $statistics = GameStatistics::fromGame($game);

            foreach ($game->playerIds() as $index => $playerId) {
                $slot = $index + 1;
                $gewonnen = (int) $statistics->pointsWon[$slot];

                $rijen[] = [
                    'player' => $this->player($playerId),
                    'season' => $this->season((int) $game->season_id),
                    'round' => [
                        'id' => (int) $game->round_id,
                        'number' => (int) $game->round_number,
                        'date' => Carbon::parse($game->round_date)->format('Y-m-d'),
                    ],
                    'day_score' => round($statistics->averages[$slot], 2),
                    'points_won' => $gewonnen,
                    'points_conceded' => $statistics->totalPoints - $gewonnen,
                ];
            }
        }

        return $this->top($rijen, $limit, fn (array $rij): array => [
            $rij['day_score'],
            $rij['points_won'] - $rij['points_conceded'],
            $rij['round']['date'],
        ]);
    }

    /**
     * Het eindgemiddelde per speler per seizoen: de stand na de laatste berekende
     * speeldag van dat seizoen.
     *
     * @param  Collection<int, Round>  $rounds
     * @param  array<int, array<int, int>>  $ranks
     * @param  array<int, array<int, float>>  $averages
     * @return list<array<string, mixed>>
     */
    private function bestSeasons(Collection $rounds, array $ranks, array $averages, int $limit): array
    {
        $rijen = [];

        foreach ($this->lastCalculatedRounds($rounds) as $round) {
            foreach ($ranks[$round->id] ?? [] as $playerId => $rank) {
                $rijen[] = [
                    'player' => $this->player($playerId),
                    'season' => $this->season((int) $round->season_id),
                    'average' => round($averages[$round->id][$playerId], 2),
                    'rank' => $rank,
                    'players_ranked' => count($ranks[$round->id]),
                ];
            }
        }

        return $this->top($rijen, $limit, fn (array $rij): array => [$rij['average']]);
    }

    /**
     * De grootste sprong tussen twee opeenvolgende geranschikte speeldagen binnen
     * hetzelfde seizoen. Per speler blijft alleen zijn grootste sprong over.
     *
     * Enkel sprongen waarbij de speler op béide speeldagen aanwezig was. Zonder die
     * voorwaarde meet deze lijst iets anders dan ze belooft: wie afwezig is krijgt
     * het verliezersgemiddelde en zakt daardoor naar de staart van het klassement,
     * dus levert de eerste avond dat hij wél komt automatisch een sprong van
     * tientallen plaatsen op. Dat is het terugveren van een boete, geen prestatie,
     * en het verdrong elke echte sprong uit de lijst.
     *
     * @param  Collection<int, Round>  $rounds
     *                                          Bij elke rij staan ook de twee gemiddeldes. Vroeg in het seizoen ligt het
     *                                          hele veld op een kluitje — na twee speeldagen is het gemiddelde (basispunten +
     *                                          twee dagscores) / 3 — dus daar levert een winst van een halve punt tientallen
     *                                          plaatsen op, en later in het seizoen een handvol. De grootste sprongen komen
     *                                          daarom structureel uit het begin van een seizoen. Dat is geen fout in de
     *                                          telling; met de gemiddeldes erbij kan de site "+82 plaatsen met 0,55 punt
     *                                          erbij" tonen in plaats van enkel het spectaculaire getal.
     * @param  array<int, array<int, int>>  $ranks
     * @param  array<int, array<int, float>>  $averages
     * @param  array<int, array<int, bool>>  $presence
     * @return list<array<string, mixed>>
     */
    private function biggestClimbs(Collection $rounds, array $ranks, array $averages, array $presence, int $limit): array
    {
        $beste = [];

        foreach ($rounds->groupBy('season_id') as $seasonId => $seizoensRondes) {
            $vorige = [];

            foreach ($seizoensRondes->sortBy('number') as $round) {
                foreach ($ranks[$round->id] ?? [] as $playerId => $rank) {
                    $beideAanwezig = ($presence[$round->id][$playerId] ?? false)
                        && ($vorige[$playerId][2] ?? false);
                    $sprong = $beideAanwezig ? $vorige[$playerId][0] - $rank : 0;

                    if ($sprong > ($beste[$playerId]['places'] ?? 0)) {
                        $beste[$playerId] = [
                            'player' => $this->player($playerId),
                            'season' => $this->season((int) $seasonId),
                            'from_round' => (int) $vorige[$playerId][1],
                            'to_round' => (int) $round->number,
                            'from_rank' => $vorige[$playerId][0],
                            'to_rank' => $rank,
                            'from_average' => round($vorige[$playerId][3], 2),
                            'to_average' => round($averages[$round->id][$playerId], 2),
                            'places' => $sprong,
                            'players_ranked' => count($ranks[$round->id]),
                        ];
                    }

                    $vorige[$playerId] = [
                        $rank,
                        (int) $round->number,
                        $presence[$round->id][$playerId] ?? false,
                        $averages[$round->id][$playerId],
                    ];
                }
            }
        }

        return $this->top(array_values($beste), $limit, fn (array $rij): array => [$rij['places']]);
    }

    /**
     * De langste reeks opeenvolgende speeldagen aanwezig, binnen één seizoen. Over
     * seizoenen heen tellen zou de zomer meerekenen als doorgespeeld.
     *
     * Een volledig seizoen is het plafond, dus bovenaan staan meerdere mensen met
     * dezelfde reeks. Daarbinnen loopt de volgorde op naam, zodat de lijst niet bij
     * elke opvraging anders staat.
     *
     * @param  Collection<int, Round>  $rounds
     * @param  array<int, array<int, bool>>  $presence
     * @return list<array<string, mixed>>
     */
    private function longestStreaks(Collection $rounds, array $presence, int $limit): array
    {
        $beste = [];

        foreach ($rounds->groupBy('season_id') as $seasonId => $seizoensRondes) {
            $geordend = $seizoensRondes->sortBy('number')->values();

            $spelers = $geordend
                ->flatMap(fn (Round $round): array => array_keys($presence[$round->id] ?? []))
                ->unique();

            foreach ($spelers as $playerId) {
                $reeks = $this->longestRun($geordend, $presence, (int) $playerId);

                if ($reeks === null || $reeks['length'] <= ($beste[$playerId]['length'] ?? 0)) {
                    continue;
                }

                $beste[$playerId] = [
                    'player' => $this->player((int) $playerId),
                    'season' => $this->season((int) $seasonId),
                    'length' => $reeks['length'],
                    'from_round' => $reeks['from'],
                    'to_round' => $reeks['to'],
                    'rounds_in_season' => $geordend->count(),
                ];
            }
        }

        return $this->top(array_values($beste), $limit, fn (array $rij): array => [
            $rij['length'],
            $this->aflopendOpNaam($rij['player']['full_name']),
        ]);
    }

    /**
     * Met wie stond wie het vaakst op dezelfde baan. Door de rotatie speelt elk
     * paar precies één set samen per wedstrijd, dus één lus over de sets en hun
     * twee kanten levert elke combinatie exact één keer.
     *
     * @param  Collection<int, Game>  $games
     * @return list<array<string, mixed>>
     */
    private function mostPlayedDuos(Collection $games, int $limit): array
    {
        $duos = [];

        foreach ($games as $game) {
            $ids = $game->playerIds();

            foreach (Game::LINE_UPS as $number => $kanten) {
                $thuisWint = (int) $game->{"set{$number}_home"} > (int) $game->{"set{$number}_away"};

                foreach ($kanten as $kantIndex => $slots) {
                    $paar = [$ids[$slots[0] - 1], $ids[$slots[1] - 1]];
                    sort($paar);
                    $sleutel = implode(':', $paar);

                    $duos[$sleutel] ??= ['players' => $paar, 'games' => 0, 'sets' => 0, 'sets_won' => 0];
                    $duos[$sleutel]['games']++;
                    $duos[$sleutel]['sets']++;

                    if ($thuisWint === ($kantIndex === 0)) {
                        $duos[$sleutel]['sets_won']++;
                    }
                }
            }
        }

        $rijen = array_map(fn (array $duo): array => [
            'players' => array_map(fn (int $id): array => $this->player($id), $duo['players']),
            'games' => $duo['games'],
            // Eén set samen per avond, dus sets == games. Toch expliciet, zodat de
            // site de noemer niet zelf hoeft af te leiden — zelfde vorm als
            // /players/{player}/pairings.
            'sets' => $duo['sets'],
            'sets_won' => $duo['sets_won'],
        ], array_values($duos));

        return $this->top($rijen, $limit, fn (array $rij): array => [$rij['games'], $rij['sets_won']]);
    }

    /* ---------------------------------------------------------------- bouwstenen */

    /**
     * De stand per speeldag over iedereen die die speeldag een gemiddelde had. Zie
     * de klassendocumentatie voor waarom dit niet de opgeslagen rank is.
     *
     * @param  list<int>  $seasonIds
     * @return array{ranks: array<int, array<int, int>>, averages: array<int, array<int, float>>}
     */
    private function standingsPerRound(array $seasonIds): array
    {
        $ranks = [];
        $averages = [];

        $rijen = DB::table('player_round_statistics as statistic')
            ->join('rounds', 'rounds.id', '=', 'statistic.round_id')
            ->whereIn('rounds.season_id', $seasonIds)
            ->whereNotNull('statistic.average')
            ->orderBy('statistic.round_id')
            ->orderByDesc('statistic.average')
            ->get(['statistic.round_id', 'statistic.player_id', 'statistic.average']);

        foreach ($rijen as $rij) {
            $roundId = (int) $rij->round_id;
            $playerId = (int) $rij->player_id;

            $ranks[$roundId][$playerId] = count($ranks[$roundId] ?? []) + 1;
            $averages[$roundId][$playerId] = (float) $rij->average;
        }

        return ['ranks' => $ranks, 'averages' => $averages];
    }

    /**
     * @param  Collection<int, Round>  $rounds
     * @return Collection<int, Round>
     */
    private function lastCalculatedRounds(Collection $rounds): Collection
    {
        return $rounds
            ->where('is_calculated', true)
            ->groupBy('season_id')
            ->map(fn (Collection $seizoen): Round => $seizoen->sortByDesc('number')->first())
            ->values();
    }

    /**
     * Enkel volledig ingevulde wedstrijden: zonder alle drie de sets is er geen
     * dagscore en geen setwinnaar.
     *
     * @param  list<int>  $seasonIds
     * @return Collection<int, Game>
     */
    private function games(array $seasonIds): Collection
    {
        return Game::query()
            ->join('rounds', 'rounds.id', '=', 'games.round_id')
            ->whereIn('rounds.season_id', $seasonIds)
            ->orderBy('games.id')
            ->select([
                'games.*',
                'rounds.number as round_number',
                'rounds.date as round_date',
                'rounds.season_id as season_id',
            ])
            ->get()
            ->filter(fn (Game $game): bool => $game->is_complete)
            ->values();
    }

    /**
     * @param  Collection<int, Round>  $rounds  op speeldagnummer gesorteerd
     * @param  array<int, array<int, bool>>  $presence
     * @return array{length: int, from: int, to: int}|null
     */
    private function longestRun(Collection $rounds, array $presence, int $playerId): ?array
    {
        $beste = null;
        $start = null;
        $lengte = 0;

        foreach ($rounds as $round) {
            if (! ($presence[$round->id][$playerId] ?? false)) {
                $lengte = 0;
                $start = null;

                continue;
            }

            $lengte++;
            $start ??= (int) $round->number;

            if ($beste === null || $lengte > $beste['length']) {
                $beste = ['length' => $lengte, 'from' => $start, 'to' => (int) $round->number];
            }
        }

        return $beste;
    }

    /**
     * Aanwezigheid per speeldag. Geen rij betekent afwezig: wie voor die speeldag
     * nooit ingeschreven werd, was er ook niet.
     *
     * @param  list<int>  $seasonIds
     * @return array<int, array<int, bool>> speeldag-id => speler-id => aanwezig
     */
    private function presencePerRound(array $seasonIds): array
    {
        $presence = [];

        $rijen = DB::table('player_round_statistics as statistic')
            ->join('rounds', 'rounds.id', '=', 'statistic.round_id')
            ->whereIn('rounds.season_id', $seasonIds)
            ->get(['statistic.round_id', 'statistic.player_id', 'statistic.is_present']);

        foreach ($rijen as $rij) {
            $presence[(int) $rij->round_id][(int) $rij->player_id] = (bool) $rij->is_present;
        }

        return $presence;
    }

    /**
     * Sorteersleutel voor een naam binnen top(), dat alles aflopend sorteert: door
     * elk teken te spiegelen komt A vóór Z te staan in een aflopende sortering.
     */
    private function aflopendOpNaam(?string $naam): string
    {
        $naam = $naam ?? '';
        $gespiegeld = '';

        for ($i = 0; $i < strlen($naam); $i++) {
            $gespiegeld .= chr(255 - ord($naam[$i]));
        }

        return $gespiegeld;
    }

    /**
     * Sorteert aflopend op de sleutels die $key oplevert en snijdt af op $limit.
     *
     * @param  list<array<string, mixed>>  $rijen
     * @param  callable(array<string, mixed>): array<int, mixed>  $key
     * @return list<array<string, mixed>>
     */
    private function top(array $rijen, int $limit, callable $key): array
    {
        usort($rijen, fn (array $a, array $b): int => $key($b) <=> $key($a));

        return array_slice($rijen, 0, $limit);
    }

    /** @return array<string, mixed> */
    private function player(int $playerId): array
    {
        $player = $this->players->get($playerId);

        return [
            'id' => $playerId,
            'first_name' => $player?->first_name,
            'last_name' => $player?->last_name,
            'full_name' => $player?->full_name,
        ];
    }

    /** @return array<string, mixed> */
    private function season(int $seasonId): array
    {
        return [
            'id' => $seasonId,
            'name' => $this->seasons->get($seasonId)?->name,
        ];
    }
}
