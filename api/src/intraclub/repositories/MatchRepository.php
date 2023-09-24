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
    SELECT MT.id, speeldag_id AS roundId, RND.Number,
        MT.Set1Home, MT.Set1Away, MT.Set2Home, MT.Set2Away, 
        MT.Set3Home, MT.Set3Away,
        PL1H.Id as Player1Id, PL1H.FirstName AS Player1FirstName, PL1H.Name AS Player1Name,
        PL2H.Id as Player2Id, PL2H.FirstName AS Player2FirstName, PL2H.Name AS Player2Name,
        PL1A.Id as Player3Id, PL1A.FirstName AS Player3FirstName, PL1A.Name AS Player3Name,
        PL2A.Id as Player4Id, PL2A.FirstName AS Player4FirstName, PL2A.Name AS Player4Name
    FROM Match MT
    INNER JOIN Round RND ON RND.id = MT.RoundId
    INNER JOIN Player PL1H ON PL1H.id =  MT.Player1Id
    INNER JOIN Player PL2H ON PL2H.id =  MT.Player2Id
    INNER JOIN Player PL1A ON PL1A.id =  MT.Player3Id
    INNER JOIN Player PL2A ON PL2A.id =  MT.Player4Id
    INNER JOIN intra_seizoen ISEASON ON ISEASON.Id = ISP.seizoen_id
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
        $query = "SELECT MT.Id, Set1Home, Set1Away, Set2Home, Set2Away,Set3Home, Set3Away,
                    PL1.Id as Player1Id, PL1.FirstName AS Player1FirstName, PL1.Name AS Player1Name,
                    PL2.Id as Player2Id, PL2.FirstName AS Player2FirstName, PL2.Name AS Player2Name,
                    PL3.Id as Player3Id, PL3.FirstName AS Player3FirstName, PL3.Name AS Player3Name,
                    PL4.Id as Player4Id, PL4.FirstName AS Player4FirstName, PL4.Name AS Player4Name,
                    RND.Id as RoundId, RND.Number AS RoundNumber
                    FROM Match MT 
                    INNER JOIN Round RND ON RND.id = MT.RoundId
                    INNER JOIN Player PL1 ON PL1.id =  MT.Player1Id
                    INNER JOIN Player PL2 ON PL2.id =  MT.Player2Id
                    INNER JOIN Player PL3 ON PL3.id =  MT.Player3Id
                    INNER JOIN Player PL4 ON PL4.id =  MT.Player4Id
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
     * @param  int $set1Home
     * @param  int $set1Away
     * @param  int $set2Home
     * @param  int $set2Away
     * @param  int $set3Home
     * @param  int $set3Away
     * @return void
     */
    public function create(
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
        $stmt = $this->db->prepare("INSERT INTO Match 
            (RoundId, Player1Id, Player2Id, Player3Id, Player4Id, Set1Home, Set1Away,
             Set2Home, Set2Away, Set3Home, Set3Away) 
            VALUES (:roundId, :player1Id, :player2Id, :player3Id, :player4Id,
             :set1Home, :set1Away, :set2Home, :set2Away, :set3Home, :set3Away)");
        $stmt->bindParam(':roundId', $roundId, PDO::PARAM_INT);
        $stmt->bindParam(':player1Id', $playerId1, PDO::PARAM_INT);
        $stmt->bindParam(':player2Id', $playerId2, PDO::PARAM_INT);
        $stmt->bindParam(':player3Id', $playerId3, PDO::PARAM_INT);
        $stmt->bindParam(':player4Id', $playerId4, PDO::PARAM_INT);
        $stmt->bindParam(':set1Home', $set1Home, PDO::PARAM_INT);
        $stmt->bindParam(':set1Away', $set1Away, PDO::PARAM_INT);
        $stmt->bindParam(':set2Home', $set2Home, PDO::PARAM_INT);
        $stmt->bindParam(':set2Away', $set2Away, PDO::PARAM_INT);
        $stmt->bindParam(':set3Home', $set3Home, PDO::PARAM_INT);
        $stmt->bindParam(':set3Away', $set3Away, PDO::PARAM_INT);

        $stmt->execute();
    }

    /**
     * Update een bestaande wedstrijd
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
     * @return void
     */
    public function update($id, $playerId1, $playerId2, $playerId3, $playerId4, $set1Home, $set1Away, $set2Home, $set2Away, $set3Home, $set3Away)
    {
        $stmt = $this->db->prepare("UPDATE intra_wedstrijden
        SET
           team1_speler1 = :playerId1,
           team1_speler2 = :playerId2,
           team2_speler1 = :playerId3,
           team2_speler2 = :playerId4,
           set1_1 = :set1Home,
           set1_2 = :set1Away,
           set2_1 = :set2Home,
           set2_2 = :set2Away,
           set3_1 = :set3Home,
           set3_2 = :set3Away
        WHERE
           id = :id");
        $stmt->bindParam(':id', $id, PDO::PARAM_INT);
        $stmt->bindParam(':playerId1', $playerId1, PDO::PARAM_INT);
        $stmt->bindParam(':playerId2', $playerId2, PDO::PARAM_INT);
        $stmt->bindParam(':playerId3', $playerId3, PDO::PARAM_INT);
        $stmt->bindParam(':playerId4', $playerId4, PDO::PARAM_INT);
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
        $stmt = $this->db->prepare("SELECT COUNT(*) as num FROM intra_wedstrijden WHERE id = ? ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row["num"] > 0;
    }
}