<?php
namespace intraclub\validators;

use \Datetime;
use intraclub\common\Utilities;
use intraclub\repositories\SeasonRepository;

class SeasonValidator {

    /**
     * Database connection
     *
     * @var PDO
     */
    protected $db;    
    /**
     * seasonRepository
     *
     * @var SeasonRepository
     */
    protected $seasonRepository;

    public function __construct($db){
        $this->db = $db;
        $this->seasonRepository = new SeasonRepository($db);
    }
    
    /**
     * Validatie creatie seizoen
     *
     * @param  string $period
     * @return array(string) errors
     */
    public function validateCreateSeason($period){
        $errors = array();
        if (!isset($period) || trim($period) === ''){
            $errors[] = "Periode moet ingevuld zijn."; 
        }
        if(empty($errors)){
            if($this->seasonRepository->existsWithPeriod($period)){
                $errors[] = "Er bestaat al een seizoen met dezelfde periode."; 
            }
        }
        return $errors;
    }
}