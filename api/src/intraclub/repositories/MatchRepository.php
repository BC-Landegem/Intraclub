<?php
namespace intraclub\repositories;

use PDO;
class MatchRepository {
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
    SELECT IW.id, speeldag_id AS roundId, ISP.speeldagnummer AS roundNumber,
        set1_1 AS firstSet_home, set1_2 AS firstSet_away, set2_1 AS secondSet_home, set2_2 AS secondSet_away, 
        set3_1 AS thirdSet_home, set3_2 AS thirdSet_away,
        PL1H.Id as home_firstPlayer_Id, PL1H.voornaam AS home_firstPlayer_firstName, PL1H.naam AS home_firstPlayer_name,
        PL2H.Id as home_secondPlayer_Id, PL2H.voornaam AS home_secondPlayer_firstName, PL2H.naam AS home_secondPlayer_name,
        PL1A.Id as away_firstPlayer_Id, PL1A.voornaam AS away_firstPlayer_firstName, PL1A.naam AS away_firstPlayer_name,
        PL2A.Id as away_secondPlayer_Id, PL2A.voornaam AS away_secondPlayer_firstName, PL2A.naam AS away_secondPlayer_name
    FROM intra_wedstrijden IW
    INNER JOIN intra_speeldagen ISP ON ISP.id = IW.speeldag_id
    INNER JOIN intra_spelers PL1H ON PL1H.id =  IW.team1_speler1
    INNER JOIN intra_spelers PL2H ON PL2H.id =  IW.team1_speler2
    INNER JOIN intra_spelers PL1A ON PL1A.id =  IW.team2_speler1
    INNER JOIN intra_spelers PL2A ON PL2A.id =  IW.team2_speler2
    INNER JOIN intra_seizoen ISEASON ON ISEASON.Id = ISP.seizoen_id
    ";

    public function __construct($db){
        $this->db = $db;
    }
    
    /**
     * Haal alle matchen op voor seizoen
     *
     * @param  int $seasonId
     * @return array of matches
     */
    public function getAllBySeasonId($seasonId){        
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
    public function getAllByRoundId($roundId){
        $stmt = $this->db->prepare($this->matchQuery . " WHERE ISP.Id=?");
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
    public function getAllBySeasonAndPlayerId($seasonId, $playerId){
        $query = "SELECT IW.Id, set1_1 AS firstSet_home, set1_2 AS firstSet_away, set2_1 AS secondSet_home, set2_2 AS secondSet_away, 
                    set3_1 AS thirdSet_home, set3_2 AS thirdSet_away,
                    PL1H.Id as home_firstPlayer_Id, PL1H.voornaam AS home_firstPlayer_firstName, PL1H.naam AS home_firstPlayer_name,
                    PL2H.Id as home_secondPlayer_Id, PL2H.voornaam AS home_secondPlayer_firstName, PL2H.naam AS home_secondPlayer_name,
                    PL1A.Id as away_firstPlayer_Id, PL1A.voornaam AS away_firstPlayer_firstName, PL1A.naam AS away_firstPlayer_name,
                    PL2A.Id as away_secondPlayer_Id, PL2A.voornaam AS away_secondPlayer_firstName, PL2A.naam AS away_secondPlayer_name,
                    ISP.Id as roundId, ISP.speeldagnummer AS roundNumber
                    FROM intra_wedstrijden IW 
                    INNER JOIN intra_speeldagen ISP ON ISP.id = IW.speeldag_id
                    INNER JOIN intra_spelers PL1H ON PL1H.id =  IW.team1_speler1
                    INNER JOIN intra_spelers PL2H ON PL2H.id =  IW.team1_speler2
                    INNER JOIN intra_spelers PL1A ON PL1A.id =  IW.team2_speler1
                    INNER JOIN intra_spelers PL2A ON PL2A.id =  IW.team2_speler2
                    WHERE (
                            (
                                PL1H.Id  = ? OR
                                PL2H.Id  = ? OR
                                PL1A.Id = ? OR
                                PL2A.Id = ?
                            ) AND ISP.seizoen_id = ?
                        )
                    ORDER BY IW.Id ASC;";
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
    public function create($roundId, $playerId1, $playerId2, $playerId3, $playerId4, $set1Home, $set1Away, $set2Home, $set2Away, $set3Home, $set3Away){
        $stmt = $this->db->prepare("INSERT INTO intra_wedstrijden 
            (speeldag_id, team1_speler1, team1_speler2, team2_speler1, team2_speler2, set1_1, set1_2, set2_1, set2_2, set3_1, set3_2) 
            VALUES (:roundId, :playerId1, :playerId2, :playerId3, :playerId4, :set1Home, :set1Away, :set2Home, :set2Away, :set3Home, :set3Away)");
        $stmt->bindParam(':roundId', $roundId, PDO::PARAM_INT);
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
    public function update($id, $playerId1, $playerId2, $playerId3, $playerId4, $set1Home, $set1Away, $set2Home, $set2Away, $set3Home, $set3Away){
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
    public function exists($id){
        $stmt = $this->db->prepare("SELECT COUNT(*) as num FROM intra_wedstrijden WHERE id = ? ");
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row["num"] > 0;
    }
}