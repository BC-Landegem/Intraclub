<?php

declare(strict_types=1);

namespace intraclub\validators;

use intraclub\common\Utilities;
use intraclub\repositories\RoundRepository;
use PDO;

class RoundValidator
{
    protected RoundRepository $roundRepository;

    public function __construct(protected PDO $db)
    {
        $this->roundRepository = new RoundRepository($db);
    }

    /**
     * Validatie creatie speeldag
     *
     * Datum = correct tekstformaat
     *
     * @param  string $date
     * @return array(string) errors
     */
    public function validateCreateRound($date): array
    {
        $errors = [];
        if (!Utilities::isDate($date)) {
            $errors[] = "Ongeldige datum voor ronde.";
        }
        if (empty($errors)) {
            if ($this->roundRepository->existsWithDate($date)) {
                $errors[] = "Er bestaat al een ronde met deze datum.";
            }
        }
        return $errors;
    }
}
