<?php

namespace App\Services;

use App\Models\Player;
use App\Models\PlayerRoundStatistic;
use App\Models\Round;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Loting van een speeldag: verdeelt de aanwezige spelers over games van vier.
 *
 * Basis is de legacy-aanpak (twee overlappende sterktegroepen, willekeurig binnen
 * de groep), met deze verbeteringen:
 * - Wie uitgeloot werd, is de volgende PROTECTED_ROUNDS speeldagen beschermd en
 *   blijft dus niet opnieuw aan de kant.
 * - Wie overblijft (1-3 spelers) wordt als uitgeloot bewaard in
 *   player_round_statistics: het overleeft een refresh, weegt mee in volgende
 *   lotingen, en de tussenstand rekent die speeldag voor hem niet mee.
 * - Spelers die al een match hebben, doen niet meer mee aan een nieuwe loting.
 *   Zo deelt een tweede loting (bv. na laatkomers) enkel de rest in.
 */
class DrawService
{
    private const PLAYERS_PER_GAME = 4;

    /** Aandeel van de deelnemers per sterktegroep (legacy: 60% met 20% overlap). */
    private const GROUP_FRACTION = 0.6;

    /** Aantal speeldagen dat een speler na een uitloting beschermd is. */
    public const PROTECTED_ROUNDS = 4;

    /**
     * Loot de speeldag en bewaar wie uitgeloot is.
     *
     * @return array{games: list<list<int>>, drawnOut: list<int>}
     */
    public function draw(Round $round): array
    {
        $result = $this->composeGames($this->participants($round));

        $this->persistDrawnOut($round, $result['drawnOut']);

        return $result;
    }

    /**
     * Aanwezige leden die nog geen match hebben, gesorteerd op sterkte. Per speler
     * houden we bij hoeveel speeldagen geleden hij uitgeloot werd.
     *
     * @return Collection<int, array{id: int, average: float, roundsSinceDrawnOut: int|null}>
     */
    private function participants(Round $round): Collection
    {
        $lastDrawnOut = $this->lastDrawnOutRoundNumbers($round);
        $averages = $this->currentAverages($round);
        $alreadyPlaying = $this->playersWithGame($round);

        return $round->playerStatistics()
            ->where('is_present', true)
            ->with('player')
            ->get()
            ->filter(fn (PlayerRoundStatistic $statistic): bool => ($statistic->player?->is_member ?? false)
                && ! in_array($statistic->player_id, $alreadyPlaying, true))
            ->map(function (PlayerRoundStatistic $statistic) use ($lastDrawnOut, $round): array {
                $lastNumber = $lastDrawnOut[$statistic->player_id] ?? null;

                return [
                    'id' => $statistic->player_id,
                    'average' => $averages[$statistic->player_id] ?? 0.0,
                    'roundsSinceDrawnOut' => $lastNumber === null ? null : $round->number - $lastNumber,
                ];
            })
            ->sortByDesc('average')
            ->values();
    }

    /**
     * Spelers die deze speeldag al in een match staan.
     *
     * @return list<int>
     */
    private function playersWithGame(Round $round): array
    {
        return $round->games()
            ->get(['player1_id', 'player2_id', 'player3_id', 'player4_id'])
            ->flatMap(fn ($game): array => $game->playerIds())
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Het nummer van de laatste speeldag waarop elke speler uitgeloot werd, binnen
     * dit seizoen en vóór de huidige speeldag.
     *
     * @return array<int, int>
     */
    private function lastDrawnOutRoundNumbers(Round $round): array
    {
        return DB::table('player_round_statistics as statistic')
            ->join('rounds', 'rounds.id', '=', 'statistic.round_id')
            ->where('rounds.season_id', $round->season_id)
            ->where('rounds.number', '<', $round->number)
            ->where('statistic.is_drawn_out', true)
            ->groupBy('statistic.player_id')
            ->selectRaw('statistic.player_id, MAX(rounds.number) as laatste')
            ->pluck('laatste', 'player_id')
            ->map(fn ($number): int => (int) $number)
            ->all();
    }

    /**
     * Laatst berekende gemiddelde per speler; valt terug op de basispunten wanneer
     * er nog geen speeldag berekend is. Bepaalt de sterktegroep.
     *
     * @return array<int, float>
     */
    private function currentAverages(Round $round): array
    {
        $averages = DB::table('player_season_statistics')
            ->where('season_id', $round->season_id)
            ->pluck('base_points', 'player_id')
            ->map(fn ($points): float => (float) $points)
            ->all();

        $calculated = DB::table('player_round_statistics as statistic')
            ->join('rounds', 'rounds.id', '=', 'statistic.round_id')
            ->where('rounds.season_id', $round->season_id)
            ->whereNotNull('statistic.average')
            ->orderBy('rounds.number')
            ->get(['statistic.player_id', 'statistic.average']);

        foreach ($calculated as $row) {
            $averages[$row->player_id] = (float) $row->average;
        }

        return $averages;
    }

    /**
     * Stel games samen uit twee overlappende sterktegroepen, beurtelings sterk en
     * zwak, zoals de legacy-loting.
     *
     * @param  Collection<int, array{id: int, average: float, roundsSinceDrawnOut: int|null}>  $participants
     * @return array{games: list<list<int>>, drawnOut: list<int>}
     */
    private function composeGames(Collection $participants): array
    {
        $count = $participants->count();
        if ($count < self::PLAYERS_PER_GAME) {
            return ['games' => [], 'drawnOut' => $participants->pluck('id')->all()];
        }

        // Eerst bepalen wie aan de kant blijft: enkel de rest na deling door vier.
        [$playing, $drawnOut] = $this->selectSittingOut($participants, $count % self::PLAYERS_PER_GAME);

        $playingCount = $playing->count();
        $groups = [
            $playing->take((int) floor($playingCount * self::GROUP_FRACTION))->all(),
            $playing->slice((int) floor($playingCount * (1 - self::GROUP_FRACTION)))->all(),
        ];

        $games = [];
        $used = [];

        do {
            $drewThisPass = false;
            foreach ($groups as $group) {
                $available = array_values(array_filter($group, fn (array $player): bool => ! isset($used[$player['id']])));
                if (count($available) < self::PLAYERS_PER_GAME) {
                    continue;
                }
                $picked = $this->pickFour($available);
                foreach ($picked as $playerId) {
                    $used[$playerId] = true;
                }
                $games[] = $picked;
                $drewThisPass = true;
            }
        } while ($drewThisPass);

        // Wie door de groepsindeling overblijft, vormt de laatste games.
        $remaining = $playing->reject(fn (array $player): bool => isset($used[$player['id']]))->values();
        while ($remaining->count() >= self::PLAYERS_PER_GAME) {
            $picked = $this->pickFour($remaining->all());
            $games[] = $picked;
            $remaining = $remaining->reject(fn (array $player): bool => in_array($player['id'], $picked, true))->values();
        }

        return [
            'games' => $games,
            'drawnOut' => [...$drawnOut->pluck('id')->all(), ...$remaining->pluck('id')->all()],
        ];
    }

    /**
     * Verdeel de deelnemers in wie speelt en wie aan de kant blijft.
     *
     * Wie de voorbije PROTECTED_ROUNDS speeldagen uitgeloot werd, is beschermd en
     * komt pas aan de beurt als er te weinig onbeschermde spelers zijn; dan valt de
     * keuze op wie het langst geleden aan de kant stond. Binnen een gelijke groep
     * beslist het toeval.
     *
     * @param  Collection<int, array{id: int, average: float, roundsSinceDrawnOut: int|null}>  $participants
     * @return array{0: Collection<int, array<string, mixed>>, 1: Collection<int, array<string, mixed>>}
     */
    private function selectSittingOut(Collection $participants, int $sitOutCount): array
    {
        if ($sitOutCount === 0) {
            return [$participants, collect()];
        }

        $sittingOut = $participants
            ->shuffle()
            ->sortBy([
                // Onbeschermde spelers eerst; daarna wie het langst geleden aan de
                // kant stond (nooit uitgeloot telt als "oneindig lang geleden").
                fn (array $a, array $b): int => ($this->isProtected($a) ? 1 : 0) <=> ($this->isProtected($b) ? 1 : 0),
                fn (array $a, array $b): int => ($b['roundsSinceDrawnOut'] ?? PHP_INT_MAX) <=> ($a['roundsSinceDrawnOut'] ?? PHP_INT_MAX),
            ])
            ->take($sitOutCount)
            ->values();

        $sittingOutIds = $sittingOut->pluck('id')->all();

        return [
            $participants->reject(fn (array $player): bool => in_array($player['id'], $sittingOutIds, true))->values(),
            $sittingOut,
        ];
    }

    /** @param array{roundsSinceDrawnOut: int|null} $player */
    private function isProtected(array $player): bool
    {
        $since = $player['roundsSinceDrawnOut'];

        return $since !== null && $since <= self::PROTECTED_ROUNDS;
    }

    /**
     * Kies vier spelers uit een groep.
     *
     * @param  list<array{id: int, average: float, roundsSinceDrawnOut: int|null}>  $available
     * @return list<int>
     */
    private function pickFour(array $available): array
    {
        shuffle($available);

        return array_map(
            fn (array $player): int => $player['id'],
            array_slice($available, 0, self::PLAYERS_PER_GAME)
        );
    }

    /**
     * Bewaar wie uitgeloot is. Spelers die eerder uitgeloot waren maar nu wél
     * ingedeeld zijn, verliezen de vlag.
     *
     * @param  list<int>  $drawnOutPlayerIds
     */
    private function persistDrawnOut(Round $round, array $drawnOutPlayerIds): void
    {
        $round->playerStatistics()->where('is_drawn_out', true)->update(['is_drawn_out' => false]);

        if ($drawnOutPlayerIds !== []) {
            $round->playerStatistics()
                ->whereIn('player_id', $drawnOutPlayerIds)
                ->update(['is_drawn_out' => true]);
        }
    }

    /**
     * Spelers die deze speeldag uitgeloot zijn en dus niet mogen meedoen.
     *
     * @return Collection<int, Player>
     */
    public function drawnOutPlayers(Round $round): Collection
    {
        return Player::query()
            ->whereIn('id', $round->playerStatistics()->where('is_drawn_out', true)->pluck('player_id'))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
    }
}
