<?php

namespace App\Services;

use App\Enums\Gender;
use App\Models\Game;
use App\Models\Player;
use App\Models\Round;
use App\Models\Season;
use Illuminate\Support\Collection;

/**
 * Bouwt het klassement (algemeen, dames, veteranen, recreanten) op basis van
 * het voortschrijdend gemiddelde na de laatst berekende speeldag. Alleen wie in
 * een van de recentste geconfigureerde speeldagen speelde krijgt dat gemiddelde
 * te zien; de anderen volgen onderaan, nog steeds volgens hun echte gemiddelde.
 *
 * 1:1 port van intraclub\managers\RankingManager uit de legacy-API.
 */
class RankingService
{
    public const CATEGORY_GENERAL = 'general';

    public const CATEGORY_WOMEN = 'women';

    public const CATEGORY_VETERANS = 'veterans';

    public const CATEGORY_RECREANTS = 'recreants';

    public const CATEGORIES = [
        self::CATEGORY_GENERAL,
        self::CATEGORY_WOMEN,
        self::CATEGORY_VETERANS,
        self::CATEGORY_RECREANTS,
    ];

    /**
     * @param  list<string>  $categories
     * @return array{season: Season|null, round: Round|null, categories: array<string, list<array<string, mixed>>>}
     */
    public function get(
        ?int $seasonId = null,
        ?int $roundId = null,
        ?int $limit = null,
        array $categories = [self::CATEGORY_GENERAL],
        bool $membersOnly = true,
    ): array {
        $season = $seasonId ? Season::find($seasonId) : Season::current();
        if ($season === null) {
            return ['season' => null, 'round' => null, 'categories' => array_fill_keys($categories, [])];
        }

        $round = $roundId
            ? Round::find($roundId)
            : $this->lastCalculatedRound($season);

        $ranking = $round === null
            ? $this->rankingForNewSeason($season, $membersOnly)
            : $this->rankingAfterRound($round, $membersOnly);
        $ranking = $this->withAverageVisibility($ranking, $season, $round);

        $previousRanking = collect();
        if ($round !== null && $round->number > 1) {
            $previousRound = $season->rounds()->where('number', $round->number - 1)->first();
            if ($previousRound !== null) {
                $previousRanking = $this->withAverageVisibility(
                    $this->rankingAfterRound($previousRound, $membersOnly),
                    $season,
                    $previousRound,
                );
            }
        }

        $result = [];
        foreach ($categories as $category) {
            $result[$category] = $this->buildCategory($ranking, $previousRanking, $category, $limit);
        }

        return ['season' => $season, 'round' => $round, 'categories' => $result];
    }

    /**
     * De eindstand van een seizoen: plaats en gemiddelde per speler, in de vorm
     * waarin /rankings ze publiceert.
     *
     * Dit is de verzameling die bepaalt wie in de stand van een seizoen hoort, en
     * ze staat hier omdat drie plaatsen ze precies gelijk moeten hebben: de stand
     * zelf, `players_count` op /seasons, en de aanwezighedentabel van
     * /seasons/{id}/statistics. Die laatste twee liepen uiteen — 2025-2026 gaf 88
     * rijen in het klassement en 121 in de statistieken — omdat een seizoensrij ook
     * bestaat voor wie op de laatste speeldag geen gemiddelde heeft.
     *
     * @return array<int, array{rank: int, average: float}> speler-id => plaats
     */
    public function finalStanding(Season $season, bool $membersOnly = true): array
    {
        $round = $this->lastCalculatedRound($season);

        $ranking = $round === null
            ? $this->rankingForNewSeason($season, $membersOnly)
            : $this->rankingAfterRound($round, $membersOnly);

        $standing = [];
        foreach ($ranking->values() as $index => $entry) {
            $standing[$entry['player']->id] = ['rank' => $index + 1, 'average' => $entry['average']];
        }

        return $standing;
    }

    public function lastCalculatedRound(Season $season): ?Round
    {
        return $season->rounds()->where('is_calculated', true)->orderByDesc('number')->first();
    }

    /**
     * @return Collection<int, array{player: Player, average: float}>
     */
    private function rankingForNewSeason(Season $season, bool $membersOnly = true): Collection
    {
        return $season->playerStatistics()
            ->with('player')
            ->join('players', 'players.id', '=', 'player_season_statistics.player_id')
            ->when($membersOnly, fn ($query) => $query->where('players.is_member', true))
            ->orderByDesc('player_season_statistics.base_points')
            ->select('player_season_statistics.*')
            ->get()
            ->map(fn ($statistic): array => [
                'player' => $statistic->player,
                'average' => (float) $statistic->base_points,
            ]);
    }

    /**
     * @return Collection<int, array{player: Player, average: float}>
     */
    private function rankingAfterRound(Round $round, bool $membersOnly = true): Collection
    {
        return $round->playerStatistics()
            ->with('player')
            ->join('players', 'players.id', '=', 'player_round_statistics.player_id')
            ->when($membersOnly, fn ($query) => $query->where('players.is_member', true))
            // Zonder gemiddelde geen plaats in het klassement. Voor leden bestaat die
            // altijd; met ?members=0 komen er rijen bij van wie nooit berekend werd.
            ->whereNotNull('player_round_statistics.average')
            ->orderByDesc('player_round_statistics.average')
            ->select('player_round_statistics.*')
            ->get()
            ->map(fn ($statistic): array => [
                'player' => $statistic->player,
                'average' => (float) $statistic->average,
            ]);
    }

    /**
     * @param  Collection<int, array{player: Player, average: float}>  $ranking
     * @return Collection<int, array{player: Player, average: float, average_visible: bool}>
     */
    private function withAverageVisibility(Collection $ranking, Season $season, ?Round $round): Collection
    {
        $activePlayerIds = collect();

        if ($round !== null) {
            $roundIds = $season->rounds()
                ->where('is_calculated', true)
                ->where('number', '<=', $round->number)
                ->orderByDesc('number')
                ->limit(max(1, (int) config('ranking.active_rounds')))
                ->pluck('id');

            $activePlayerIds = Game::query()
                ->whereIn('round_id', $roundIds)
                ->get(['player1_id', 'player2_id', 'player3_id', 'player4_id'])
                ->flatMap(fn (Game $game): array => $game->playerIds())
                ->unique()
                ->flip();
        }

        return $ranking
            ->map(function (array $entry) use ($activePlayerIds): array {
                $entry['average_visible'] = $activePlayerIds->has($entry['player']->id);

                return $entry;
            })
            ->sort(function (array $left, array $right): int {
                return ($right['average_visible'] <=> $left['average_visible'])
                    ?: ($right['average'] <=> $left['average'])
                    ?: ($left['player']->id <=> $right['player']->id);
            })
            ->values();
    }

    /**
     * @param  Collection<int, array{player: Player, average: float, average_visible: bool}>  $ranking
     * @param  Collection<int, array{player: Player, average: float, average_visible: bool}>  $previousRanking
     * @return list<array<string, mixed>>
     */
    private function buildCategory(Collection $ranking, Collection $previousRanking, string $category, ?int $limit): array
    {
        $filter = $this->categoryFilter($category);
        $current = $ranking->filter(fn (array $entry): bool => $filter($entry['player']))->values();
        $previousIds = $previousRanking
            ->filter(fn (array $entry): bool => $filter($entry['player']))
            ->values()
            ->map(fn (array $entry): int => $entry['player']->id);

        return $current
            ->take($limit ?? $current->count())
            ->map(function (array $entry, int $index) use ($previousIds): array {
                $rank = $index + 1;

                // Legacy-gedrag: geen vorige ranking ⇒ 0; speler niet gevonden in de
                // vorige ranking ⇒ array_search geeft false (0) ⇒ verschil = 1 - rank.
                $difference = 0;
                if ($previousIds->isNotEmpty()) {
                    $foundIndex = $previousIds->search($entry['player']->id);
                    $difference = ((int) $foundIndex + 1) - $rank;
                }

                return [
                    'id' => $entry['player']->id,
                    'first_name' => $entry['player']->first_name,
                    'last_name' => $entry['player']->last_name,
                    'full_name' => $entry['player']->full_name,
                    'average' => $entry['average_visible'] ? round($entry['average'], 2) : null,
                    'average_text' => $entry['average_visible'] ? null : config('ranking.inactive_text'),
                    'is_active' => $entry['average_visible'],
                    'rank' => $rank,
                    'difference' => $difference,
                ];
            })
            ->all();
    }

    private function categoryFilter(string $category): callable
    {
        return match ($category) {
            self::CATEGORY_GENERAL => fn (Player $player): bool => true,
            self::CATEGORY_WOMEN => fn (Player $player): bool => $player->gender === Gender::Female,
            self::CATEGORY_VETERANS => fn (Player $player): bool => $player->is_veteran,
            self::CATEGORY_RECREANTS => fn (Player $player): bool => $player->is_recreant,
        };
    }
}
