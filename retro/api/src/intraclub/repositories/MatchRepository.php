<?php
namespace intraclub\repositories;

use PDO;

class MatchRepository
{
    /**
     * Database connection
     *
     * @var PDO
     */
    protected $db;

    /**
     * Query om wedstrijden op te halen, inclusief alle spelers
     *
     * @var string
     */
    protected $matchQuery = "
    SELECT MT.id, MT.roundId, RND.number as roundNumber,
        MT.set1Home, MT.set1Away, MT.set2Home, MT.set2Away, 
        MT.set3Home, MT.set3Away,
        PL1H.Id as player1Id, PL1H.FirstName AS player1FirstName, PL1H.Name AS player1Name,
        PL2H.Id as player2Id, PL2H.FirstName AS player2FirstName, PL2H.Name AS player2Name,
        PL1A.Id as player3Id, PL1A.FirstName AS player3FirstName, PL1A.Name AS player3Name,
        PL2A.Id as player4Id, PL2A.FirstName AS player4FirstName, PL2A.Name AS player4Name
    FROM `Match` MT
    INNER JOIN `Round` RND ON RND.id = MT.roundId
    INNER JOIN Player PL1H ON PL1H.id =  MT.player1Id
    INNER JOIN Player PL2H ON PL2H.id =  MT.player2Id
    INNER JOIN Player PL1A ON PL1A.id =  MT.player3Id
    INNER JOIN Player PL2A ON PL2A.id =  MT.player4Id
    ";

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Haal alle matchen op voor seizoen
     *
     * @param  int $seasonId
     * @return array of matches
     */
    public function getAllBySeasonId($seasonId)
    {
        $stmt = $this->db->prepare($this->matchQuery . " WHERE ISEASON.Id=?");
        $stmt->execute([$seasonId]);
        return $stmt->fetchAll();
    }

    /**
     * Haal alle matchen op voor ronde
     *
     * @param  int $roundId
     * @return array of matches
     */
    public function getAllByRoundId($roundId)
    {
        $stmt = $this->db->prepare($this->matchQuery . " WHERE RND.Id=?");
        $stmt->execute([$roundId]);
        return $stmt->fetchAll();
    }

    /**
     * Haal alle matchen op voor seizoen en speler
     *
     * @param  int $seasonId
     * @param  int $playerId
     * @return array of matches
     */
    public function getAllBySeasonAndPlayerId($seasonId, $playerId)
    {
        $query = "SELECT MT.id, set1Home, set1Away, set2Home, set2Away, set3Home, set3Away,
                    PL1.Id as player1Id, PL1.FirstName AS player1FirstName, PL1.Name AS player1Name,
                    PL2.Id as player2Id, PL2.FirstName AS player2FirstName, PL2.Name AS player2Name,
                    PL3.Id as player3Id, PL3.FirstName AS player3FirstName, PL3.Name AS player3Name,
                    PL4.Id as player4Id, PL4.FirstName AS player4FirstName, PL4.Name AS player4Name,
                    RND.Id as roundId, RND.Number AS roundNumber
                    FROM `Match` MT 
                    INNER JOIN Round RND ON RND.id = MT.RoundId
                    INNER JOIN Player PL1 ON PL1.id =  MT.player1Id
                    INNER JOIN Player PL2 ON PL2.id =  MT.player2Id
                    INNER JOIN Player PL3 ON PL3.id =  MT.player3Id
                    INNER JOIN Player PL4 ON PL4.id =  MT.player4Id
                    WHERE (
                            (
                                PL1.Id  = ? OR
                                PL2.Id  = ? OR
                                PL3.Id = ? OR
                                PL4.Id = ?
                            ) AND RND.SeasonId = ?
                        )
                    ORDER BY MT.Id ASC;";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$playerId, $playerId, $playerId, $playerId, $seasonId]);
        return $stmt->fetchAll();
    }

    /**
     * Maak een nieuwe wedstrijd aan
     *
     * @param  int $roundId
     * @param  int $playerId1
     * @param  int $playerId2
     * @param  int $playerId3
     * @param  int $playerId4
     * @return int
     */
    public function create(
        $roundId,
        $playerId1,
        $playerId2,
        $playerId3,
        $playerId4
    ) {
        $stmt = $this->db->prepare("INSERT INTO `Match` 
            (RoundId, Player1Id, Player2Id, Player3Id, Player4Id, Set1Home, Set1Away,
             Set2Home, Set2Away, Set3Home, Set3Away) 
            VALUES (:roundId, :player1Id, :player2Id, :player3Id, :player4Id,
             0,0,0,0,0,0)");
        $stmt->bindParam(':roundId', $roundId, PDO::PARAM_INT);
        $stmt->bindParam(':player1Id', $playerId1, PDO::PARAM_INT);
        $stmt->bindParam(':player2Id', $playerId2, PDO::PARAM_INT);
        $stmt->bindParam(':player3Id', $playerId3, PDO::PARAM_INT);
        $stmt->bindParam(':player4Id', $playerId4, PDO::PARAM_INT);

        $stmt->execute();
        return $this->db->lastInsertId();

    }

    /**
     * Update een bestaande wedstrijd
     *
     * @param  int $id
     * @param  int $set1Home
     * @param  int $set1Away
     * @param  int $set2Home
     * @param  int $set2Away
     * @param  int $set3Home
     * @param  int $set3Away
     * @return bool
     */
    public function update($id, $set1Home, $set1Away, $set2Home, $set2Away, $set3Home, $set3Away)
    {
        $stmt = $this->db->prepare("UPDATE `Match`
        SET
            Set1Home = :set1Home,
            Set1Away = :set1Away,
            Set2Home = :set2Home,
            Set2Away = :set2Away,
            Set3Home = :set3Home,
            Set3Away = :set3Away
        WHERE
           Id = :id");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':set1Home', $set1Home, PDO::PARAM_INT);
        $stmt->bindParam(':set1Away', $set1Away, PDO::PARAM_INT);
        $stmt->bindParam(':set2Home', $set2Home, PDO::PARAM_INT);
        $stmt->bindParam(':set2Away', $set2Away, PDO::PARAM_INT);
        $stmt->bindParam(':set3Home', $set3Home, PDO::PARAM_INT);
        $stmt->bindParam(':set3Away', $set3Away, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Controleer of match bestaat
     *
     * @param  mixed $id
     * @return bool
     */
    public function exists($id)
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) as num FROM `Match` WHERE id = ? ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row["num"] > 0;
    }
}