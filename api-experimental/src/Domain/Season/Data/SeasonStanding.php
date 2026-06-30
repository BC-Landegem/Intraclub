<?php

declare(strict_types=1);

namespace App\Domain\Season\Data;

use App\Domain\Player\Data\PlayerStatistics;
use JsonSerializable;

final class SeasonStanding implements JsonSerializable
{
    public function __construct(
        public readonly int $id,
        public readonly string $firstName,
        public readonly string $name,
        public readonly PlayerStatistics $statistics,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['firstName'],
            (string) $row['name'],
            PlayerStatistics::fromRow($row),
        );
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
        ];
    }
}
