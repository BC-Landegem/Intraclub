<?php

declare(strict_types=1);

namespace App\Domain\Round\Data;

use JsonSerializable;

final class AvailabilityEntry implements JsonSerializable
{
    public function __construct(
        public readonly int $playerId,
        public readonly bool $present,
        public readonly bool $drawnOut,
        public readonly ?float $average,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['playerId'],
            (bool) $row['present'],
            (bool) $row['drawnOut'],
            $row['average'] !== null ? (float) $row['average'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'playerId' => $this->playerId,
            'present' => $this->present,
            'drawnOut' => $this->drawnOut,
            'average' => $this->average,
        ];
    }
}
