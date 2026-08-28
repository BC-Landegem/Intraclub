<?php

namespace App\Http\Resources\Archive;

use App\Models\Archive\ArchiveGame;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Gearchiveerde wedstrijd: vaste teams, best-of-3. Een derde set ontbreekt
 * wanneer de match al na twee sets beslist was.
 *
 * `team1`/`team2` en niet `home`/`away` zoals in het huidige format: daar
 * wisselen de duo's per set, hier spelen dezelfde twee koppels de hele match.
 *
 * @mixin ArchiveGame
 */
class ArchiveGameResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        [$team1Sets, $team2Sets] = $this->sets_won;

        return [
            'id' => $this->id,
            'round_id' => $this->archive_round_id,
            'team1' => ArchivePlayerResource::collection([$this->team1Player1, $this->team1Player2]),
            'team2' => ArchivePlayerResource::collection([$this->team2Player1, $this->team2Player2]),
            'sets' => array_values(array_filter([
                $this->set($this->set1_home, $this->set1_away),
                $this->set($this->set2_home, $this->set2_away),
                $this->set($this->set3_home, $this->set3_away),
            ])),
            'sets_won' => ['team1' => $team1Sets, 'team2' => $team2Sets],
        ];
    }

    /** @return array{team1: int, team2: int}|null */
    private function set(?int $thuis, ?int $uit): ?array
    {
        return $thuis === null || $uit === null ? null : ['team1' => $thuis, 'team2' => $uit];
    }
}
