<?php

declare(strict_types=1);

namespace App\Domain\Player\Data;

use JsonSerializable;

/**
 * A won / lost / total triple (points or sets).
 */
final class StatLine implements JsonSerializable
{
    public function __construct(
        public readonly int $won,
        public readonly int $total,
    ) {
    }

    public function lost(): int
    {
        return $this->total - $this->won;
    }

    /**
     * @return array<string, int>
     */
    public function jsonSerialize(): array
    {
        return [
            'won' => $this->won,
            'lost' => $this->lost(),
            'total' => $this->total,
        ];
    }
}
