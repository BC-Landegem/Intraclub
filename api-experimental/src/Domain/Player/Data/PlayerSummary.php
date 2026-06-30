<?php

declare(strict_types=1);

namespace App\Domain\Player\Data;

use App\Domain\Player\Enum\Gender;
use JsonSerializable;

final class PlayerSummary implements JsonSerializable
{
    public function __construct(
        public readonly int $id,
        public readonly string $firstName,
        public readonly string $name,
        public readonly Gender $gender,
        public readonly int $doubleRanking,
        public readonly bool $playsCompetition,
        public readonly bool $member,
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
            Gender::from((string) $row['gender']),
            (int) $row['doubleRanking'],
            (bool) $row['playsCompetition'],
            (bool) $row['member'],
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
            'gender' => $this->gender->value,
            'doubleRanking' => $this->doubleRanking,
            'playsCompetition' => $this->playsCompetition,
            'member' => $this->member,
        ];
    }
}
