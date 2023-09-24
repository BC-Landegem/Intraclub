<?php
namespace intraclub\repositories;

use PDO;

class PlayerRepository
{
    /**
     * Database connection
     *
     * @var PDO
     */
    protected $db;

    /**
     * Basisinfo speler
     *
     * @var string
     */
    protected $playerQuery = "SELECT IPLAYER.Id, IPLAYER.Firstname, IPLAYER.Name,
    IPLAYER.PlaysCompetition, IPLAYER.Member,
    IPLAYER.Gender, IPLAYER.doubleRanking, IPLAYER.BirthDate
    FROM player IPLAYER";

    /**
     * Spelerinfo mét seizoensgegevens
     *
     * @var string
     */
    protected $playerWithSeasonInfoQuery = "
    SELECT IPLAYER.id, IPLAYER.FirstName, IPLAYER.Name, IPLAYER.Member,
        IPLAYER.Gender, IPLAYER.DoubleRanking,
        ISPS.BasePoints, ISPS.SetsPlayed, ISPS.SetsWon, ISPS.PointsPlayed,
        ISPS.PointsWon, ISPS.RoundsPresent
        FROM Player IPLAYER
        INNER JOIN PlayerSeasonStatistic ISPS ON ISPS.PlayerId = IPLAYER.Id
        WHERE ISPS.SeasonId = ?";

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Haal alle spelers op
     *
     * @param  bool $onlyMembers enkel leden of alle spelers
     * @return array met spelers- en seizoeninfo
     */
    public function getAll($onlyMembers = true)
    {
        $query = $this->playerQuery;

        if ($onlyMembers) {
            $query = $query . " WHERE IPLAYER.Member = true";
        }
        $query = $query . " ORDER BY FirstName, Name";


        $data = $this->db->query($query)->fetchAll();
        return $data;
    }
    /**
     * Controle of speler bestaat
     *
     * @param  int $id
     * @return bool true indien speler bestaat
     */
    public function exists($id)
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) as num FROM Player WHERE Id = ? ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row["num"] > 0;
    }
    /**
     * Controle of speler bestaat én lid is
     *
     * @param  int $id
     * @return bool indien speler bestaat en lid is
     */
    public function existsAndIsMember($id)
    {
        $stmt = $this->db->prepare("SELECT Id, Member FROM Player WHERE Id = ? ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row && $row["Member"] == 1;
    }

    /**
     * Haal alle spelers op, met seizoensinfo
     *
     * @param  int $seasonId
     * @param  bool $onlyMembers true om enkel leden op te halen
     * @return array met spelers- en seizoeninfo
     */
    public function getAllWithSeasonInfo($seasonId, $onlyMembers = true)
    {
        $query = $this->playerWithSeasonInfoQuery;

        if ($onlyMembers) {
            $query = $query . " AND IPLAYER.Member = true";
        }
        $query = $query . " ORDER BY FirstName, Name";

        $stmt = $this->db->prepare($query);
        $stmt->execute([$seasonId]);
        return $stmt->fetchAll();
    }

    /**
     * Haal speler op met seizoensinfo
     *
     * @param  int $id
     * @param  int $seasonId
     * @return array met speler + seizoeninfo
     */
    public function getByIdWithSeasonInfo($id, $seasonId)
    {
        $query = $this->playerWithSeasonInfoQuery . " AND IPLAYER.Id=?";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$seasonId, $id]);
        return $stmt->fetch();
    }

    /**
     * Haal basisinfo speler op
     *
     * @param  int $id
     * @return array met spelerinfo
     */
    public function getById($id)
    {
        $stmt = $this->db->prepare($this->playerQuery . " WHERE IPLAYER.Id=?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }



    /**
     * Geslachten in database
     *
     * @return array<string>
     */
    public function getPossibleGenders()
    {
        $enum = array();
        $stmt = $this->db->prepare('SHOW COLUMNS FROM Player WHERE field=\'gender\'');
        $stmt->execute();
        $row = $stmt->fetch();
        foreach (explode("','", substr($row["Type"], 6, -2)) as $v) {
            $enum[] = $v;
        }
        return $enum;
    }

    /**
     * Maak een nieuwe speler aan
     *
     * @param  string $firstName
     * @param  string $name
     * @param  string $gender
     * @param  string $birthDate
     * @param  int $doubleRanking
     * @param  bool $playsCompetition
     * @param  int $basePoints
     * @return int id van nieuwe speler
     */
    public function create($firstName, $name, $gender, $birthDate, $doubleRanking, $playsCompetition)
    {
        $playsCompetitionInteger = $playsCompetition ? 1 : 0;

        $stmt = $this->db->prepare("INSERT INTO Player
            SET 
            FirstName = :firstName,
            [Name] = :lastName,
            Gender = :gender,
            BirthDate = :birthDate,
            DoubleRanking = :doubleRanking,
            PlaysCompetition = :playsCompetition,
            Member = 1");

        $stmt->bindParam(':firstName', $firstName, PDO::PARAM_STR);
        $stmt->bindParam(':lastName', $name, PDO::PARAM_STR);
        $stmt->bindParam(':gender', $gender, PDO::PARAM_STR);
        $stmt->bindParam(':birthDate', $birthDate, PDO::PARAM_INT);
        $stmt->bindParam(':doubleRanking', $doubleRanking, PDO::PARAM_STR);
        $stmt->bindParam(':playsCompetition', $playsCompetitionInteger, PDO::PARAM_INT);

        $stmt->execute();
        return $this->db->lastInsertId();
    }
    /**
     * Update een bestaande speler
     *
     * @param  int $id
     * @param  string $firstName
     * @param  string $name
     * @param  string $gender
     * @param  bool $isYouth
     * @param  bool $isVeteran
     * @param  string $ranking
     * @return void
     */
    public function update($id, $firstName, $name, $gender, $isYouth, $isVeteran, $ranking)
    {
        $isYouthInteger = $isYouth ? 1 : 0;
        $isVeteranInteger = $isVeteran ? 1 : 0;

        $stmt = $this->db->prepare("UPDATE intra_spelers
            SET 
            voornaam = :firstName,
            naam = :lastName,
            geslacht = :gender,
            jeugd = :isYouth,
            klassement = :ranking,
            is_veteraan = :isVeteran,
            is_lid = 1
            WHERE id = :id");

        $stmt->bindParam(':firstName', $firstName, PDO::PARAM_STR);
        $stmt->bindParam(':lastName', $name, PDO::PARAM_STR);
        $stmt->bindParam(':gender', $gender, PDO::PARAM_STR);
        $stmt->bindParam(':isYouth', $isYouthInteger, PDO::PARAM_INT);
        $stmt->bindParam(':ranking', $ranking, PDO::PARAM_STR);
        $stmt->bindParam(':isVeteran', $isVeteranInteger, PDO::PARAM_INT);
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Maak seizoenstatistieken aan (nieuw seizoen of nieuwe speler)
     *
     * @param  int $seasonId
     * @param  int $playerId
     * @param  int $basePoints
     * @return void
     */
    public function createSeasonStatistic($seasonId, $playerId, $basePoints)
    {
        $insertPlayerSeasonQuery = "INSERT INTO PlayerSeasonStatistic
            SET
                PlayerId = :playerId,
                SeasonId = :seasonId,
                BasePoints = :basePoints,
                SetsPlayed = 0,
                SetsWon = 0,
                PointsPlayed = 0,
                PointsWon = 0,
                MatchesPlayed = 0,
                MatchesWon = 0
                ";
        $insertPlayerSeasonStmt = $this->db->prepare($insertPlayerSeasonQuery);
        $insertPlayerSeasonStmt->bindParam(':basePoints', $basePoints, PDO::PARAM_STR);
        $insertPlayerSeasonStmt->bindParam(':seasonId', $seasonId, PDO::PARAM_INT);
        $insertPlayerSeasonStmt->bindParam(':playerId', $playerId, PDO::PARAM_INT);
        $insertPlayerSeasonStmt->execute();
    }
    /**
     * Update seizoensstatistieken (bereken tussenstand)
     *
     * @param  int $seasonId
     * @param  int $playerId
     * @param  int $setsPlayed
     * @param  int $setsWon
     * @param  int $pointsPlayed
     * @param  int $pointsWon
     * @param  int $roundsPresent
     * @return void
     */
    public function updateSeasonStatistic($seasonId, $playerId, $setsPlayed, $setsWon, $pointsPlayed, $pointsWon, $roundsPresent)
    {

        $updatePlayerSeasonStmt = $this->db->prepare("UPDATE PlayerSeasonStatistic
            SET
                SetsPlayed = :setsPlayed,
                SetsWon = :setsWon,
                PointsPlayed= :pointsPlayed,
                PointsWon = :pointsWon,
                MatchesPlayed = :matchesPlayed,
                MatchesWon = :matchesWon,
                RoundsPresent = :roundsPresent

            WHERE PlayerId = :playerId AND SeasonId = :seasonId");

        $updatePlayerSeasonStmt->bindParam(':setsPlayed', $setsPlayed, PDO::PARAM_INT);
        $updatePlayerSeasonStmt->bindParam(':setsWon', $setsWon, PDO::PARAM_INT);
        $updatePlayerSeasonStmt->bindParam(':pointsPlayed', $pointsPlayed, PDO::PARAM_INT);
        $updatePlayerSeasonStmt->bindParam(':pointsWon', $pointsWon, PDO::PARAM_INT);
        $updatePlayerSeasonStmt->bindParam(':matchesPlayed', $matchesPlayed, PDO::PARAM_INT);
        $updatePlayerSeasonStmt->bindParam(':matchesWon', $matchesWon, PDO::PARAM_INT);
        $updatePlayerSeasonStmt->bindParam(':playerId', $playerId, PDO::PARAM_INT);
        $updatePlayerSeasonStmt->bindParam(':seasonId', $seasonId, PDO::PARAM_INT);
        $updatePlayerSeasonStmt->bindParam(':roundsPresent', $roundsPresent, PDO::PARAM_INT);
        $updatePlayerSeasonStmt->execute();
    }

    /**
     * Voeg (of update) rondestatistieken (bereken tussenstand)
     *
     * @param  int $roundId
     * @param  int $playerId
     * @param  int $average
     * @return void
     */
    public function insertOrUpdateRoundStatistic($roundId, $playerId, $average)
    {

        $updatePlayerSeasonStmt = $this->db->prepare("INSERT INTO
            PlayerRoundStatistic
            SET
                Average = :average,
                PlayerId = :playerId,
                RoundId = :roundId
            ON DUPLICATE KEY UPDATE
                Average = :average");

        $updatePlayerSeasonStmt->bindParam(':average', $average, PDO::PARAM_STR);
        $updatePlayerSeasonStmt->bindParam(':playerId', $playerId, PDO::PARAM_INT);
        $updatePlayerSeasonStmt->bindParam(':roundId', $roundId, PDO::PARAM_INT);
        $updatePlayerSeasonStmt->execute();
    }

}