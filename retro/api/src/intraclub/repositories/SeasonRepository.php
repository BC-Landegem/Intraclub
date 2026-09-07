<?php

namespace intraclub\repositories;


class SeasonRepository
{
    /**
     * Database connection
     *
     * @var \PDO
     */
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Haal huidig seizoen op
     *
     * @return int seizoen
     */
    public function getCurrentSeasonId(): int
    {
        $currentSeason = $this->db->query("SELECT Id FROM Season ORDER BY Id DESC LIMIT 1;")->fetch();
        return $currentSeason["Id"];
    }
    /**
     * Controle of er een seizoen bestaat met zelfde naam
     *
     * @param  string $name
     * @return bool true indien naam reeds bestaat
     */
    public function exists($name)
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) as num FROM Season WHERE Name = ? ");
        $stmt->execute([$name]);
        $row = $stmt->fetch();
        return $row["num"] > 0;
    }
    /**
     * Haal statistieken op voor gegeven seizoen
     *
     * @param  int $seasonId
     * @return array spelerinfo met seizoenstatistieken
     */
    public function getStatistics($seasonId)
    {
        $query = "SELECT IPLAYER.id, IPLAYER.firstName, IPLAYER.name, 
                ISPS.setsPlayed, ISPS.setsWon, ISPS.pointsPlayed,
                ISPS.pointsWon, ISPS.matchesPlayed,
                ISPS.roundsPresent
            FROM Player IPLAYER
            INNER JOIN PlayerSeasonStatistic ISPS ON ISPS.PlayerId = IPLAYER.Id
            WHERE ISPS.SeasonId = ? AND IPLAYER.Member = 1
            ORDER BY ISPS.roundsPresent desc, ISPS.setsWon desc, ISPS.basePoints desc;";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$seasonId]);
        return $stmt->fetchAll();
    }

    /**
     * Maak een nieuw seizoen aan
     *
     * @param  string $period
     * @return int id of toegevoegd seizoen
     */
    public function create($period)
    {
        $insertSeasonQuery = "INSERT INTO Season (Name) VALUES (?)";
        $insertStmt = $this->db->prepare($insertSeasonQuery);
        $insertStmt->execute([$period]);
        return $this->db->lastInsertId();
    }


}