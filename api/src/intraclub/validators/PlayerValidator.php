<?php
namespace intraclub\validators;

use DateTime;
use intraclub\repositories\PlayerRepository;
use intraclub\common\Utilities;

class PlayerValidator
{

    /**
     * Database connection
     *
     * @var \PDO
     */
    protected $db;
    /**
     * playerRepository
     *
     * @var PlayerRepository
     */
    protected $playerRepository;

    public function __construct($db)
    {
        $this->db = $db;
        $this->playerRepository = new PlayerRepository($db);
    }

    /**
     * Validatie creatie nieuwe speler
     *
     * @param  string $firstName
     * @param  string $name
     * @param  string $gender
     * @param  string $birthDate
     * @param  int $doubleRanking
     * @param  int $playsCompetition
     * @param  int $basePoints
     * @return array(string) errors
     */
    public function validateNewPlayer(
        $firstName,
        $name,
        $gender,
        $birthDate,
        $doubleRanking,
        $playsCompetition,
        $basePoints
    ) {
        $errors = array();
        $errors = $this->validatePlayer($firstName, $name, $gender, $birthDate, $doubleRanking, $playsCompetition, $errors);
        if (Utilities::isInt($basePoints) === false) {
            $errors[] = "Ongeldige basispunten";
        } else if ($basePoints < 0 || $basePoints > 21) {
            $errors[] = "Basispunten ongeldig";
        }
        return $errors;
    }

    /**
     * Validatie aanpassen bestaande speler
     *
     * @param  int $id
     * @param  string $firstName
     * @param  string $name
     * @param  string $gender
     * @param  string $birthDate
     * @param  int $doubleRanking
     * @param  int $playsCompetition
     * @return array(string) errors
     */
    public function validateExistingPlayer($id, $firstName, $name, $gender, $birthDate, $doubleRanking, $playsCompetition)
    {
        $errors = array();
        if (!$this->playerRepository->exists($id)) {
            $errors[] = "Speler met gegeven id bestaat niet!";
        }
        $errors = $this->validatePlayer($firstName, $name, $gender, $birthDate, $doubleRanking, $playsCompetition, $errors);
        return $errors;
    }

    /**
     * Validatie speler
     * 
     *
     * @param  string $firstName
     * @param  string $name
     * @param  string $gender
     * @param  string $birthDate
     * @param  int $doubleRanking
     * @param  bool $playsCompetition
     * @param  array(string) errors
     * @return array(string) errors
     */
    private function validatePlayer($firstName, $name, $gender, $birthDate, $doubleRanking, $playsCompetition, $errors)
    {
        if (!isset($firstName) || trim($firstName) === '') {
            $errors[] = "Voornaam moet ingevuld zijn.";
        }
        if (!isset($name) || trim($name) === '') {
            $errors[] = "Naam moet ingevuld zijn.";
        }
        if (!in_array($gender, $this->playerRepository->getPossibleGenders())) {
            $errors[] = "Onbekend geslacht";
        }
        if ($doubleRanking < 0 || $doubleRanking > 12) {
            $errors[] = "Onbekende ranking";
        }
        if (!Utilities::isDate($birthDate)) {
            $errors[] = "Ongeldige geboortedatum";
        } else if (Utilities::isDateInFuture($birthDate)) {
            $errors[] = "Geboortedatum in de toekomst";
        }
        if (!is_bool($playsCompetition)) {
            $errors[] = "Speelt speler competitie?";
        }

        return $errors;
    }
}