<?php

declare(strict_types=1);

namespace App\Domain\Player\Service;

use App\Domain\Player\Enum\Gender;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Validates player input. Throws InvalidArgumentException (rendered as HTTP 400)
 * with all collected error messages when the input is invalid.
 */
final class PlayerValidator
{
    /**
     * @param array<string, mixed> $data
     */
    public function validate(array $data, bool $requireBasePoints): void
    {
        $errors = $this->collectErrors($data);

        if ($requireBasePoints) {
            $basePoints = $data['basePoints'] ?? null;
            if (!is_numeric($basePoints)) {
                $errors[] = 'Ongeldige basispunten';
            } elseif ($basePoints < 0 || $basePoints > 21) {
                $errors[] = 'Basispunten ongeldig';
            }
        }

        if ($errors) {
            throw new InvalidArgumentException(implode(' ', $errors));
        }
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<int, string>
     */
    private function collectErrors(array $data): array
    {
        $errors = [];

        if (trim((string) ($data['firstName'] ?? '')) === '') {
            $errors[] = 'Voornaam moet ingevuld zijn';
        }
        if (trim((string) ($data['name'] ?? '')) === '') {
            $errors[] = 'Naam moet ingevuld zijn';
        }
        if (!is_string($data['gender'] ?? null) || Gender::tryFrom($data['gender']) === null) {
            $errors[] = 'Onbekend geslacht';
        }
        $doubleRanking = $data['doubleRanking'] ?? null;
        if (!is_numeric($doubleRanking) || $doubleRanking < 0 || $doubleRanking > 12) {
            $errors[] = 'Onbekende ranking';
        }
        $birthDate = (string) ($data['birthDate'] ?? '');
        if (!$this->isDate($birthDate)) {
            $errors[] = 'Ongeldige geboortedatum';
        } elseif ($this->isDateInFuture($birthDate)) {
            $errors[] = 'Geboortedatum in de toekomst';
        }
        if (!is_bool($data['playsCompetition'] ?? null)) {
            $errors[] = 'Speelt speler competitie?';
        }

        return $errors;
    }

    private function isDate(string $date, string $format = 'Y-m-d'): bool
    {
        $parsed = DateTimeImmutable::createFromFormat($format, $date);

        return $parsed && $parsed->format($format) === $date;
    }

    private function isDateInFuture(string $date, string $format = 'Y-m-d'): bool
    {
        $parsed = DateTimeImmutable::createFromFormat($format, $date);

        return $parsed && $parsed > new DateTimeImmutable();
    }
}
