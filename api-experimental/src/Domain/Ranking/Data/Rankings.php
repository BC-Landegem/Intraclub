<?php

declare(strict_types=1);

namespace App\Domain\Ranking\Data;

use JsonSerializable;

/**
 * A set of rankings for a season. Only the requested categories are populated;
 * unset categories are omitted from the JSON output.
 */
final class Rankings implements JsonSerializable
{
    /**
     * @param array<int, RankingEntry>|null $general
     * @param array<int, RankingEntry>|null $women
     * @param array<int, RankingEntry>|null $veterans
     * @param array<int, RankingEntry>|null $recreants
     */
    public function __construct(
        public readonly int $seasonId,
        public readonly ?array $general = null,
        public readonly ?array $women = null,
        public readonly ?array $veterans = null,
        public readonly ?array $recreants = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $categories = [
            'general' => $this->general,
            'women' => $this->women,
            'veterans' => $this->veterans,
            'recreants' => $this->recreants,
        ];

        $data = ['seasonId' => $this->seasonId];
        foreach ($categories as $key => $value) {
            if ($value !== null) {
                $data[$key] = $value;
            }
        }

        return $data;
    }
}
