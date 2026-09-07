<?php
namespace intraclub\validators;

use \Datetime;
use intraclub\common\Utilities;
use intraclub\repositories\RoundRepository;

class RoundValidator
{

    /**
     * Database connection
     *
     * @var \PDO
     */
    protected $db;
    /**
     * roundRepository
     *
     * @var RoundRepository
     */
    protected $roundRepository;

    public function __construct($db)
    {
        $this->db = $db;
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
    public function validateCreateRound($date)
    {
        $errors = array();
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