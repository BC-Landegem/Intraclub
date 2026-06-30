<?php

declare(strict_types=1);

namespace App\Domain\Player\Data;

use App\Domain\Match\Data\MatchResult;
use JsonSerializable;

final class PlayerProfile implements JsonSerializable
{
    /**
     * @param array<int, MatchResult> $matches
     * @param array<int, RankingHistoryEntry> $rankingHistory
     */
    public function __construct(
        public readonly int $id,
        public readonly string $firstName,
        public readonly string $name,
        public readonly PlayerStatistics $statistics,
        public readonly array $matches,
        public readonly array $rankingHistory,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'firstName' => $this->firstName,
            'name' => $this->name,
            'statistics' => $this->statistics,
            'matches' => $this->matches,
            'rankingHistory' => $this->rankingHistory,
        ];
    }
}
