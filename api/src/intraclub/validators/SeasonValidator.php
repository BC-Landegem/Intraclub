<?php
namespace intraclub\validators;

use \Datetime;
use intraclub\common\Utilities;
use intraclub\repositories\SeasonRepository;

class SeasonValidator
{

    /**
     * Database connection
     *
     * @var \PDO
     */
    protected $db;
    /**
     * seasonRepository
     *
     * @var SeasonRepository
     */
    protected $seasonRepository;

    public function __construct($db)
    {
        $this->db = $db;
        $this->seasonRepository = new SeasonRepository($db);
    }

    /**
     * Validatie creatie seizoen
     *
     * @param  string $name
     * @return array(string) errors
     */
    public function validateCreateSeason($name)
    {
        $errors = array();
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