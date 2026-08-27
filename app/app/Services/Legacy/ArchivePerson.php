<?php

namespace App\Services\Legacy;

/**
 * Eén persoon, samengesteld uit wat de drie generaties over hem weten:
 * comp_spelers (2009-2013), intra_spelers (2013-2023) en het huidige ledenbestand.
 */
class ArchivePerson
{
    /** @param list<string> $notes */
    public function __construct(
        public ?object $comp = null,
        public ?object $intra = null,
        public ?int $playerId = null,
        public string $firstName = '',
        public string $lastName = '',
        public ?string $gender = null,
        public ?string $ranking = null,
        public string $status = '',
        public string $score = '',
        public string $compLink = '',
        public array $notes = [],
    ) {}

    public function fullName(): string
    {
        return trim("{$this->firstName} {$this->lastName}");
    }

    /** Vraagt deze rij nog een menselijke beslissing? */
    public function needsReview(): bool
    {
        return in_array($this->status, ['AMBIGU', 'VOORSTEL'], true)
            || str_starts_with($this->compLink, 'voorstel');
    }
}
