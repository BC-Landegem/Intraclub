<?php

declare(strict_types=1);

namespace App\Domain\Player\Data;

use JsonSerializable;

final class PlayerStatistics implements JsonSerializable
{
    public function __construct(
        public readonly StatLine $points,
        public readonly StatLine $sets,
        public readonly int $matchesPlayed,
        public readonly int $roundsPresent,
    ) {
    }

    /**
     * Build from a player-season-statistics row.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            new StatLine((int) $row['pointsWon'], (int) $row['pointsPlayed']),
            new StatLine((int) $row['setsWon'], (int) $row['setsPlayed']),
            (int) $row['matchesPlayed'],
            (int) $row['roundsPresent'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'points' => $this->points,
            'sets' => $this->sets,
            'matchesPlayed' => $this->matchesPlayed,
            'roundsPresent' => $this->roundsPresent,
        ];
    }
}
