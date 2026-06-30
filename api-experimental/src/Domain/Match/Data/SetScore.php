<?php

declare(strict_types=1);

namespace App\Domain\Match\Data;

use JsonSerializable;

final class SetScore implements JsonSerializable
{
    public function __construct(
        public readonly int $home,
        public readonly int $away,
    ) {
    }

    /**
     * @return array<string, int>
     */
    public function jsonSerialize(): array
    {
        return [
            'home' => $this->home,
            'away' => $this->away,
        ];
    }
}
