<?php

declare(strict_types=1);

namespace App\Domain\Ranking\Data;

use JsonSerializable;

final class RankingEntry implements JsonSerializable
{
    public function __construct(
        public readonly int $id,
        public readonly string $firstName,
        public readonly string $name,
        public readonly float $average,
        public readonly int $rank,
        public readonly int $difference,
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
            'average' => $this->average,
            'rank' => $this->rank,
            'difference' => $this->difference,
        ];
    }
}
