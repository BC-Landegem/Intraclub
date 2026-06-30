<?php

declare(strict_types=1);

namespace App\Domain\Player\Data;

use JsonSerializable;

final class RankingHistoryEntry implements JsonSerializable
{
    public function __construct(
        public readonly int $roundId,
        public readonly int $roundNumber,
        public readonly float $average,
        public readonly int $rank,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['roundId'],
            (int) $row['number'],
            round((float) $row['average'], 2),
            (int) $row['rank'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'roundId' => $this->roundId,
            'roundNumber' => $this->roundNumber,
            'average' => $this->average,
            'rank' => $this->rank,
        ];
    }
}
