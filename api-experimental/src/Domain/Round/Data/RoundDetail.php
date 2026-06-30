<?php

declare(strict_types=1);

namespace App\Domain\Round\Data;

use App\Domain\Match\Data\MatchResult;
use DateTimeImmutable;
use JsonSerializable;

final class RoundDetail implements JsonSerializable
{
    /**
     * @param array<int, MatchResult> $matches
     * @param array<int, AvailabilityEntry> $availability
     */
    public function __construct(
        public readonly int $id,
        public readonly int $number,
        public readonly DateTimeImmutable $date,
        public readonly ?float $averageAbsent,
        public readonly bool $calculated,
        public readonly array $matches,
        public readonly array $availability,
    ) {
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, MatchResult> $matches
     * @param array<int, AvailabilityEntry> $availability
     */
    public static function fromRow(array $row, array $matches, array $availability): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['number'],
            new DateTimeImmutable((string) $row['date']),
            $row['averageAbsent'] !== null ? (float) $row['averageAbsent'] : null,
            (bool) $row['calculated'],
            $matches,
            $availability,
        );
    }

    /**
     * @param array<int, MatchResult> $matches
     * @param array<int, AvailabilityEntry> $availability
     */
    public static function fromSummary(RoundSummary $summary, array $matches, array $availability): self
    {
        return new self(
            $summary->id,
            $summary->number,
            $summary->date,
            $summary->averageAbsent,
            $summary->calculated,
            $matches,
            $availability,
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
            'matches' => $this->matches,
            'availability' => $this->availability,
        ];
    }
}
