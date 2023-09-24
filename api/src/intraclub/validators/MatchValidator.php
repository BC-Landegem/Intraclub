<?php

namespace intraclub\validators;

use intraclub\repositories\SeasonRepository;
use intraclub\repositories\MatchRepository;
use intraclub\repositories\RoundRepository;
use intraclub\repositories\PlayerRepository;

use intraclub\common\Utilities;

class MatchValidator
{

    /**
     * Database connection
     *
     * @var \PDO
     */
    protected $db;
    /**
     * matchRepository
     *
     * @var MatchRepository
     */
    protected $matchRepository;
    /**
     * roundRepository
     *
     * @var RoundRepository
     */
    protected $roundRepository;

    /**
     * playerRepository
     *
     * @var PlayerRepository
     */
    protected $playerRepository;

    public function __construct($db)
    {
        $this->db = $db;
        $this->matchRepository = new MatchRepository($db);
        $this->roundRepository = new RoundRepository($db);
        $this->playerRepository = new PlayerRepository($db);
    }

    /**
     * Validatie creatie wedstrijd
     *
     * @param  int $roundId
     * @param  int $playerId1
     * @param  int $playerId2
     * @param  int $playerId3
     * @param  int $playerId4
     * @param  int $set1Home
     * @param  int $set1Away
     * @param  int $set2Home
     * @param  int $set2Away
     * @param  int $set3Home
     * @param  int $set3Away
     * @return array(string) errors
     */
    public function validateCreateMatch(
        $roundId,
        $playerId1,
        $playerId2,
        $playerId3,
        $playerId4,
        $set1Home,
        $set1Away,
        $set2Home,
        $set2Away,
        $set3Home,
        $set3Away
    ) {

        $errors = array();

        //Controleer of ronde bestaat
        if (!$this->roundRepository->exists($roundId)) {
            $errors[] = "Ronde bestaat niet.";
        }
        //Validatie wedstrijd
        $errors = $this->validateMatch(
            $playerId1,
            $playerId2,
            $playerId3,
            $playerId4,
            $set1Home,
            $set1Away,
            $set2Home,
            $set2Away,
            $set3Home,
            $set3Away,
            $errors
        );

        return $errors;
    }

    /**
     * Validatie update wedstrijd
     *
     * @param  int $id
     * @param  int $playerId1
     * @param  int $playerId2
     * @param  int $playerId3
     * @param  int $playerId4
     * @param  int $set1Home
     * @param  int $set1Away
     * @param  int $set2Home
     * @param  int $set2Away
     * @param  int $set3Home
     * @param  int $set3Away
     * @return array(string) errors
     */
    public function validateUpdateMatch(
        $id,
        $playerId1,
        $playerId2,
        $playerId3,
        $playerId4,
        $set1Home,
        $set1Away,
        $set2Home,
        $set2Away,
        $set3Home,
        $set3Away
    ) {

        $errors = array();

        //Controleer of match bestaat
        if (!$this->matchRepository->exists($id)) {
            $errors[] = "Match bestaat niet.";
        }
        $errors = $this->validateMatch(
            $playerId1,
            $playerId2,
            $playerId3,
            $playerId4,
            $set1Home,
            $set1Away,
            $set2Home,
            $set2Away,
            $set3Home,
            $set3Away,
            $errors
        );

        return $errors;
    }

    /**
     * Valideer westrijd
     * 
     * Spelers moeten lid zijn
     * Setstanden moeten kloppen
     *
     * @param  int $playerId1
     * @param  int $playerId2
     * @param  int $playerId3
     * @param  int $playerId4
     * @param  int $set1Home
     * @param  int $set1Away
     * @param  int $set2Home
     * @param  int $set2Away
     * @param  int $set3Home
     * @param  int $set3Away
     * @return array(string) errors
     */
    private function validateMatch(
        $playerId1,
        $playerId2,
        $playerId3,
        $playerId4,
        $set1Home,
        $set1Away,
        $set2Home,
        $set2Away,
        $set3Home,
        $set3Away,
        $errors
    ) {
        //Controleer of spelers bestaan én moeten lid zijn
        if (!$this->playerRepository->existsAndIsMember($playerId1)) {
            $errors[] = "Eerste thuisspeler is geen lid.";
        }
        if (!$this->playerRepository->existsAndIsMember($playerId2)) {
            $errors[] = "Tweede thuisspeler is geen lid.";
        }
        if (!$this->playerRepository->existsAndIsMember($playerId3)) {
            $errors[] = "Eerste uitspeler is geen lid.";
        }
        if (!$this->playerRepository->existsAndIsMember($playerId4)) {
            $errors[] = "Tweede uitspeler is geen lid.";
        }

        //Basisvalidatie
        //SET 1
        $errors = $this->checkIfValidNumber($set1Home, "Thuisscore eerste set", $errors);
        $errors = $this->checkIfValidNumber($set1Away, "Uitscore eerste set", $errors);

        //SET 2
        $errors = $this->checkIfValidNumber($set2Home, "Thuisscore tweede set", $errors);
        $errors = $this->checkIfValidNumber($set2Away, "Uitscore tweede set", $errors);

        //SET 3
        $errors = $this->checkIfValidNumber($set3Home, "Thuisscore derde set", $errors);
        $errors = $this->checkIfValidNumber($set3Away, "Uitscore derde set", $errors);

        if (!empty($errors)) {
            return $errors;
        }

        //Verdere validatie setscores
        //SET 1
        $errors = $this->checkSet($set1Home, $set1Away, "eerste set", $errors);

        //SET 2
        $errors = $this->checkSet($set2Home, $set2Away, "tweede set", $errors);

        //SET 3
        $errors = $this->checkSet($set3Home, $set3Away, "derde set", $errors);
        return $errors;
    }


    /**
     * Controle of set klopt
     * 
     * 30-29 of 29-30
     * 21+ - x waarbij x steeds = 21+ -2
     *
     * @param  int $homeScore
     * @param  int $awayScore
     * @param  string $message
     * @param  array(string) $errors
     * @return array(string) errors
     */
    private function checkSet($homeScore, $awayScore, $message, $errors)
    {
        //Uitzondering: 30-29
        if (
            ($homeScore === 30 && $awayScore === 29) ||
            ($awayScore === 30 && $homeScore === 29)
        ) {
            return $errors;
        }
        //Indien normale set
        if (
            ($homeScore >= 21 && $homeScore > $awayScore && $awayScore > $homeScore - 2) ||
            ($awayScore >= 21 && $awayScore > $homeScore && $homeScore > $awayScore - 2)
        ) {
            $errors[] = "Foutieve score voor " . $message;
        }
        return $errors;
    }

    /**
     * Controle of score kan
     *
     * @param  int $setScore
     * @param  string $message
     * @param  array(string) $errors
     * @return array(string) errors
     */
    private function checkIfValidNumber($setScore, $message, $errors)
    {
        if (Utilities::isInt($setScore) === false) {
            $errors[] = $message . "  is ongeldig";
        } else if ($setScore < 0 || $setScore > 30) {
            $errors[] = $message . "  is een ongeldig getal";
        }
        return $errors;
    }
}