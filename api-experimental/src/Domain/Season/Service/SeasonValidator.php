<?php

declare(strict_types=1);

namespace App\Domain\Season\Service;

use App\Domain\Season\Repository\SeasonRepository;
use DomainException;

/**
 * Validates season input. Throws DomainException (rendered as HTTP 400)
 * with all collected error messages when the input is invalid.
 */
final class SeasonValidator
{
    public function __construct(private SeasonRepository $seasonRepository)
    {
    }

    public function validateCreateSeason(string $name): void
    {
        $errors = [];

        if (trim($name) === '') {
            $errors[] = 'Periode moet ingevuld zijn.';
        }

        if (!$errors && $this->seasonRepository->exists($name)) {
            $errors[] = 'Er bestaat al een seizoen met dezelfde periode.';
        }

        if ($errors) {
            throw new DomainException(implode(' ', $errors));
        }
    }
}
