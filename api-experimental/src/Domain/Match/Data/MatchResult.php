<?php

declare(strict_types=1);

namespace App\Domain\Match\Data;

use JsonSerializable;

final class MatchResult implements JsonSerializable
{
    public function __construct(
        public readonly int $id,
        public readonly int $roundId,
        public readonly int $roundNumber,
        public readonly PlayerRef $homePlayer1,
        public readonly PlayerRef $homePlayer2,
        public readonly PlayerRef $awayPlayer1,
        public readonly PlayerRef $awayPlayer2,
        public readonly SetScore $set1,
        public readonly SetScore $set2,
        public readonly SetScore $set3,
    ) {
    }

    /**
     * Hydrate from a database row produced by the match query.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['roundId'],
            (int) $row['roundNumber'],
            new PlayerRef((int) $row['player1Id'], (string) $row['player1FirstName'], (string) $row['player1Name']),
            new PlayerRef((int) $row['player2Id'], (string) $row['player2FirstName'], (string) $row['player2Name']),
            new PlayerRef((int) $row['player3Id'], (string) $row['player3FirstName'], (string) $row['player3Name']),
            new PlayerRef((int) $row['player4Id'], (string) $row['player4FirstName'], (string) $row['player4Name']),
            new SetScore((int) $row['set1Home'], (int) $row['set1Away']),
            new SetScore((int) $row['set2Home'], (int) $row['set2Away']),
            new SetScore((int) $row['set3Home'], (int) $row['set3Away']),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'round' => ['id' => $this->roundId, 'number' => $this->roundNumber],
            'home' => [$this->homePlayer1, $this->homePlayer2],
            'away' => [$this->awayPlayer1, $this->awayPlayer2],
            'sets' => [$this->set1, $this->set2, $this->set3],
        ];
    }
}
