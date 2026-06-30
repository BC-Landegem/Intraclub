<?php

declare(strict_types=1);

namespace App\Domain\Match\Data;

use JsonSerializable;

final class PlayerRef implements JsonSerializable
{
    public function __construct(
        public readonly int $id,
        public readonly string $firstName,
        public readonly string $name,
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
        ];
    }
}
