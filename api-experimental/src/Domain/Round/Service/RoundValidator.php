<?php

declare(strict_types=1);

namespace App\Domain\Round\Service;

use App\Domain\Round\Repository\RoundRepository;
use DateTimeImmutable;
use DomainException;

/**
 * Validates round (speeldag) input. Throws DomainException (rendered as HTTP 400)
 * with all collected error messages when the input is invalid.
 */
final class RoundValidator
{
    public function __construct(private RoundRepository $roundRepository)
    {
    }

    public function validateCreateRound(string $date): void
    {
        $errors = [];

        if (!$this->isDate($date)) {
            $errors[] = 'Ongeldige datum voor ronde.';
        }

        if (empty($errors) && $this->roundRepository->existsWithDate($date)) {
            $errors[] = 'Er bestaat al een ronde met deze datum.';
        }

        if ($errors) {
            throw new DomainException(implode(' ', $errors));
        }
    }

    private function isDate(string $date, string $format = 'Y-m-d'): bool
    {
        $d = DateTimeImmutable::createFromFormat($format, $date);

        return $d && $d->format($format) === $date;
    }
}
