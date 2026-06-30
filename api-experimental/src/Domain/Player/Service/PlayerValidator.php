<?php

declare(strict_types=1);

namespace App\Domain\Player\Service;

use App\Domain\Player\Repository\PlayerRepository;
use DateTime;
use InvalidArgumentException;

/**
 * Validates player input. Throws InvalidArgumentException (rendered as HTTP 400)
 * with all collected error messages when the input is invalid.
 */
final class PlayerValidator
{
    public function __construct(private PlayerRepository $repository)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function validate(array $data, bool $requireBasePoints): void
    {
        $errors = $this->collectErrors($data);

        if ($requireBasePoints) {
            $basePoints = $data['basePoints'] ?? null;
            if (filter_var($basePoints, FILTER_VALIDATE_INT) === false) {
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
        if (!in_array($data['gender'] ?? null, $this->repository->getPossibleGenders(), true)) {
            $errors[] = 'Onbekend geslacht';
        }
        $doubleRanking = $data['doubleRanking'] ?? null;
        if ($doubleRanking < 0 || $doubleRanking > 12) {
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
        $parsed = DateTime::createFromFormat($format, $date);

        return $parsed && $parsed->format($format) === $date;
    }

    private function isDateInFuture(string $date, string $format = 'Y-m-d'): bool
    {
        $parsed = DateTime::createFromFormat($format, $date);

        return $parsed && $parsed > new DateTime();
    }
}
