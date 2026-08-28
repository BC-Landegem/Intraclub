<?php

namespace App\Services;

use App\Enums\Gender;
use App\Models\Player;
use App\Models\Round;
use App\Models\Season;
use Illuminate\Support\Collection;

/**
 * Bouwt het klassement (algemeen, dames, veteranen, recreanten) op basis van
 * het voortschrijdend gemiddelde na de laatst berekende speeldag, of op de
 * basispunten wanneer het seizoen nog geen berekende speeldag heeft.
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
            : $season->rounds()->where('is_calculated', true)->orderByDesc('number')->first();

        $ranking = $round === null
            ? $this->rankingForNewSeason($season, $membersOnly)
            : $this->rankingAfterRound($round, $membersOnly);

        $previousRanking = collect();
        if ($round !== null && $round->number > 1) {
            $previousRound = $season->rounds()->where('number', $round->number - 1)->first();
            if ($previousRound !== null) {
                $previousRanking = $this->rankingAfterRound($previousRound, $membersOnly);
            }
        }

        $result = [];
        foreach ($categories as $category) {
            $result[$category] = $this->buildCategory($ranking, $previousRanking, $category, $limit);
        }

        return ['season' => $season, 'round' => $round, 'categories' => $result];
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
     * @param  Collection<int, array{player: Player, average: float}>  $previousRanking
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
                    'average' => round($entry['average'], 2),
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
