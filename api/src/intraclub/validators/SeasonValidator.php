<?php

declare(strict_types=1);

namespace intraclub\validators;

use intraclub\repositories\SeasonRepository;
use PDO;

class SeasonValidator
{
    protected SeasonRepository $seasonRepository;

    public function __construct(protected PDO $db)
    {
        $this->seasonRepository = new SeasonRepository($db);
    }

    /**
     * Validatie creatie seizoen
     *
     * @param  string $name
     * @return array(string) errors
     */
    public function validateCreateSeason($name): array
    {
        $errors = [];
        if (!isset($name) || trim($name) === '') {
            $errors[] = "Periode moet ingevuld zijn.";
        }
        if (empty($errors)) {
            if ($this->seasonRepository->exists($name)) {
                $errors[] = "Er bestaat al een seizoen met dezelfde periode.";
            }
        }
        return $errors;
    }
}
