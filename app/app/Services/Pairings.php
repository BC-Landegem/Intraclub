<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Player;
use Illuminate\Support\Collection;

/**
 * Met wie speelde een speler, en met welk resultaat.
 *
 * Door de rotatie in `Game::LINE_UPS` speelt iedereen op een baan precies één set
 * mét elk van de andere drie en twee sets tégen elk van hen. "Vaakste partner" is
 * dus hetzelfde als "vaakst op dezelfde baan gestaan" — daar valt niets te
 * definiëren. Wat wél iets zegt is het resultaat: met wie win je die ene set, en
 * tegen wie win je die twee.
 *
 * De setwinnaar volgt dezelfde regel als het klassement: een gelijke set telt als
 * winst voor het uitduo (zie GameStatistics).
 *
 * Onvolledige wedstrijden blijven buiten de telling — zonder alle drie de sets
 * valt er niets te vergelijken. Een tweede game op dezelfde avond (een invaller
 * die aanvult) telt hier wél mee: je hebt met die mensen gespeeld. Voor het
 * klassement doet die game niet mee, dus deze cijfers en `statistics.sets` hoeven
 * niet gelijk te lopen.
 */
class Pairings
{
    /**
     * @return list<array<string, mixed>>
     */
    public function forPlayer(Player $player, ?int $seasonId): array
    {
        $totalen = [];

        foreach ($this->games($player->id, $seasonId) as $game) {
            if ($game->is_complete) {
                $this->countGame($totalen, $game, $player->id);
            }
        }

        return $this->present($totalen);
    }

    /** @param array<int, array<string, mixed>> $totalen */
    private function countGame(array &$totalen, Game $game, int $playerId): void
    {
        $ids = $game->playerIds();
        $slot = array_search($playerId, $ids, true) + 1;

        foreach ($ids as $anderId) {
            if ($anderId !== $playerId) {
                $this->ensure($totalen, $anderId);
                $totalen[$anderId]['games']++;
            }
        }

        foreach (Game::LINE_UPS as $number => [$homeSlots, $awaySlots]) {
            $isHome = in_array($slot, $homeSlots, true);
            $gewonnen = $this->wonSet($game, $number, $isHome);

            $partner = array_values(array_diff($isHome ? $homeSlots : $awaySlots, [$slot]));
            $this->tel($totalen, $ids[$partner[0] - 1], 'as_partner', $gewonnen);

            foreach ($isHome ? $awaySlots : $homeSlots as $tegenstanderSlot) {
                $this->tel($totalen, $ids[$tegenstanderSlot - 1], 'as_opponent', $gewonnen);
            }
        }
    }

    /** @return Collection<int, Game> */
    private function games(int $playerId, ?int $seasonId): Collection
    {
        return Game::query()
            ->join('rounds', 'rounds.id', '=', 'games.round_id')
            ->where('rounds.season_id', $seasonId)
            ->where(fn ($query) => $query
                ->orWhere('games.player1_id', $playerId)
                ->orWhere('games.player2_id', $playerId)
                ->orWhere('games.player3_id', $playerId)
                ->orWhere('games.player4_id', $playerId))
            ->orderBy('games.id')
            ->select('games.*')
            ->get();
    }

    private function wonSet(Game $game, int $number, bool $isHome): bool
    {
        $home = (int) $game->{"set{$number}_home"};
        $away = (int) $game->{"set{$number}_away"};

        // Gelijk = winst voor het uitduo, zoals in GameStatistics.
        return $isHome ? $home > $away : $away >= $home;
    }

    /** @param array<int, array<string, mixed>> $totalen */
    private function ensure(array &$totalen, int $playerId): void
    {
        $totalen[$playerId] ??= [
            'games' => 0,
            'as_partner' => ['sets' => 0, 'sets_won' => 0],
            'as_opponent' => ['sets' => 0, 'sets_won' => 0],
        ];
    }

    /** @param array<int, array<string, mixed>> $totalen */
    private function tel(array &$totalen, int $playerId, string $rol, bool $gewonnen): void
    {
        $this->ensure($totalen, $playerId);

        $totalen[$playerId][$rol]['sets']++;

        if ($gewonnen) {
            $totalen[$playerId][$rol]['sets_won']++;
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $totalen
     * @return list<array<string, mixed>>
     */
    private function present(array $totalen): array
    {
        if ($totalen === []) {
            return [];
        }

        $spelers = Player::query()->whereIn('id', array_keys($totalen))->get()->keyBy('id');

        return collect($totalen)
            ->map(fn (array $rij, int $playerId): array => [
                'player' => [
                    'id' => $playerId,
                    'first_name' => $spelers[$playerId]->first_name,
                    'last_name' => $spelers[$playerId]->last_name,
                    'full_name' => $spelers[$playerId]->full_name,
                ],
            ] + $rij)
            // [sleutel, richting] en geen closures: sortBy() roept een closure aan
            // als vergelijkingsfunctie ($a, $b), niet als sleutelfunctie, en dan
            // sorteert hij stilzwijgend verkeerd.
            ->sortBy([
                ['games', 'desc'],
                ['as_partner.sets_won', 'desc'],
                ['player.full_name', 'asc'],
            ])
            ->values()
            ->all();
    }
}
