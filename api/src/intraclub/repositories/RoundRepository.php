<?php
namespace intraclub\repositories;

use PDO;

class RoundRepository
{
    /**
     * Database connection
     *
     * @var PDO
     */
    protected $db;

    /**
     * Speeldag query: basisinfo én aantal gespeelde matchen
     *
     * @var string
     */
    protected $roundQuery = "SELECT RND.id, RND.number, ROUND(RND.AverageAbsent,2) AS averageAbsent, 
    RND.date, RND.calculated, (SELECT COUNT(MT.id) FROM `Match` MT where MT.RoundId = RND.Id) as matches
    FROM Round RND";

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Haal alle speeldagen van een seizoen op
     *
     * @param  int $seasonId
     * @return ?array speeldagen
     */
    public function getAll($seasonId = null)
    {
        if (empty($seasonId)) {
            return null;
        }
        $stmt = $this->db->prepare($this->roundQuery . " WHERE RND.seasonId = ? ORDER BY RND.id ASC;");

        $stmt->execute([$seasonId]);
        return $stmt->fetchAll();
    }

    /**
     * Maak een nieuwe speeldag aan
     *
     * @param  int $seasonId
     * @param  string $date
     * @param  int $roundNumber
     * @return void
     */
    public function create($seasonId, $date, $roundNumber)
    {

        $stmt = $this->db->prepare("INSERT INTO Round (SeasonId, Date,
             Number, AverageAbsent, Calculated, DrawClosed) 
             VALUES (:seasonId, :date, :roundNumber, 0, 0, 0)");
        $stmt->bindParam(':seasonId', $seasonId, PDO::PARAM_INT);
        $stmt->bindParam(':date', $date, PDO::PARAM_STR);
        $stmt->bindParam(':roundNumber', $roundNumber, PDO::PARAM_INT);

        $stmt->execute();
    }

    /**
     * Pas het gemiddelde voor afwezigen aan
     *
     * @param  int $id
     * @param  int $averageAbsent
     * @return void
     */
    public function updateAverageAbsent($id, $averageAbsent)
    {

        $updateRoundstmt = $this->db->prepare("UPDATE ROUND
        SET
            AverageAbsent = ?,
            Calculated = 1
        WHERE Id = ?");

        $updateRoundstmt->bindParam(1, $averageAbsent, PDO::PARAM_STR);
        $updateRoundstmt->bindParam(2, $id, PDO::PARAM_INT);
        $updateRoundstmt->execute();
    }
    /**
     * Controle of er een speeldag bestaat op datum
     *
     * @param  string $date
     * @return bool true indien speeldag bestaat
     */
    public function existsWithDate($date)
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) as num FROM Round WHERE `Date` = ? ");
        $stmt->execute([$date]);
        $row = $stmt->fetch();
        return $row["num"] > 0;
    }
    /**
     * Controle of speeldag bestaat
     *
     * @param  int $id
     * @return bool true indien speeldag bestaat
     */
    public function exists($id)
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) as num FROM Round WHERE id = ? ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row["num"] > 0;
    }
    /**
     * Haal ronde op
     *
     * @param  int $id
     * @return array speeldag
     */
    public function getById($id)
    {
        $stmt = $this->db->prepare($this->roundQuery . " WHERE RND.Id=?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }
    /**
     * Ronde op basis van nummer en seizoen
     *
     * @param  int $seasonId
     * @param  int $number
     * @return array speeldag
     */
    public function getBySeasonAndNumber($seasonId, $number)
    {
        $stmt = $this->db->prepare($this->roundQuery . " WHERE RND.seasonId = :seasonId and RND.number = :roundNumber;");
        $stmt->execute(array(':seasonId' => $seasonId, ':roundNumber' => $number));
        return $stmt->fetch();
    }

    /**
     * Haal laatste speeldag op van seizoen
     *
     * @param  int $seasonId
     * @return array speeldag
     */
    public function getLast($seasonId)
    {
        $stmt = $this->db->prepare($this->roundQuery . " WHERE RND.SeasonId=? ORDER BY RND.Number DESC LIMIT 1;");
        $stmt->execute([$seasonId]);
        return $stmt->fetch();
    }

    /**
     * Haal laatste berekende speeldag op
     *
     * @param  int $seasonId
     * @return array speeldag
     */
    public function getLastCalculated($seasonId)
    {
        $stmt = $this->db->prepare($this->roundQuery .
            " WHERE RND.SeasonId=? AND RND.Calculated = 1 
                ORDER BY RND.Number DESC LIMIT 1;");
        $stmt->execute([$seasonId]);
        return $stmt->fetch();
    }

    /**
     * Haal speeldag op, inclusief wedstrijden
     *
     * @param  int $id
     * @return array speeldag met wedstrijden
     */
    public function getWithMatches($id)
    {
        if (empty($id)) {
            return null;
        }
        $stmt = $this->db->prepare("SELECT RND.id, RND.number, ROUND(RND.averageAbsent,2) AS averageAbsent, 
            RND.date, RND.calculated, set1Home,set1Away, set2Home, set2Away, set3Home, set3Away,
            PL1H.Id as player1Id, PL1H.firstName AS player1FirstName, PL1H.name AS player1Name,
            PL2H.Id as player2Id, PL2H.firstName AS player2FirstName, PL2H.name AS player2Name,
            PL1A.Id as player3Id, PL1A.firstName AS player3FirstName, PL1A.name AS player3Name,
            PL2A.Id as player4Id, PL2A.firstName AS player4FirstName, PL2A.name AS player4Name
            FROM `Round` RND
            INNER JOIN `Match` MT ON MT.roundId = RND.id
            INNER JOIN Player PL1H ON PL1H.id =  MT.Player1Id
            INNER JOIN Player PL2H ON PL2H.id =  MT.Player2Id
            INNER JOIN Player PL1A ON PL1A.id =  MT.Player3Id
            INNER JOIN Player PL2A ON PL2A.id =  MT.Player4Id WHERE RND.id=?
            ORDER BY MT.Id ASC;
        ");
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    }

    public function getAvailabilityData($id)
    {
        $stmt = $this->db->prepare("SELECT playerId, present, drawnOut, average

            FROM `PlayerRoundStatistic`
        ");
        $stmt->execute([$id]);
        return $stmt->fetchAll();
    }
}