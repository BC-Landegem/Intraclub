<?php

declare(strict_types=1);

namespace App\Domain\Round\Data;

use DateTimeImmutable;
use JsonSerializable;

final class RoundSummary implements JsonSerializable
{
    public function __construct(
        public readonly int $id,
        public readonly int $number,
        public readonly DateTimeImmutable $date,
        public readonly ?float $averageAbsent,
        public readonly bool $calculated,
        public readonly int $matchCount,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['number'],
            new DateTimeImmutable((string) $row['date']),
            $row['averageAbsent'] !== null ? (float) $row['averageAbsent'] : null,
            (bool) $row['calculated'],
            (int) $row['matchCount'],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'number' => $this->number,
            'date' => $this->date->format('Y-m-d'),
            'averageAbsent' => $this->averageAbsent,
            'calculated' => $this->calculated,
            'matchCount' => $this->matchCount,
        ];
    }
}
