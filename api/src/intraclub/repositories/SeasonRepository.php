<?php

namespace intraclub\repositories;


class SeasonRepository
{
    /**
     * Database connection
     *
     * @var PDO
     */
    protected $db;

    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Haal huidig seizoen op
     *
     * @return array seizoen
     */
    public function getCurrentSeasonId(): int
    {
        $currentSeason = $this->db->query("SELECT id, seizoen as season FROM intra_seizoen ORDER BY id DESC LIMIT 1;")->fetch();
        return $currentSeason["id"];
    }
    /**
     * Controle of er een seizoen bestaat met zelfde periode
     *
     * @param  string $period
     * @return bool true indien periode reeds bestaat
     */
    public function existsWithPeriod($period)
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) as num FROM intra_seizoen WHERE seizoen = ? ");
        $stmt->execute([$period]);
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
        $query = "SELECT IPLAYER.id, IPLAYER.voornaam AS firstname, IPLAYER.naam AS name, 
                ISPS.gespeelde_sets AS setsPlayed, ISPS.gewonnen_sets AS setsWon, ISPS.gespeelde_punten AS pointsPlayed,
                ISPS.gewonnen_punten AS pointsWon, ISPS.gespeelde_matchen as matchesPlayed, ISPS.gewonnen_matchen AS matchesWon,
                ISPS.speeldagen_aanwezig AS roundsPresent
            FROM intra_spelers IPLAYER
            INNER JOIN intra_spelerperseizoen ISPS ON ISPS.speler_id = IPLAYER.Id
            WHERE ISPS.seizoen_id = ? AND IPLAYER.is_lid = 1
            ORDER BY ISPS.speeldagen_aanwezig desc, ISPS.gewonnen_matchen desc, ISPS.basispunten desc";
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
        $insertSeasonQuery = "INSERT INTO intra_seizoen (seizoen) VALUES (?)";
        $insertStmt = $this->db->prepare($insertSeasonQuery);
        $insertStmt->execute([$period]);
        return $this->db->lastInsertId();
    }

    /**
     * Maak seizoenstatistieken aan (nieuw seizoen of nieuwe speler)
     *
     * @param  int $seasonId
     * @param  int $playerId
     * @param  int $basePoints
     * @return void
     */
    public function createSeasonPlayerStatistic($seasonId, $playerId, $basePoints)
    {
        $insertPlayerSeasonQuery = "INSERT INTO SeasonPlayerStatistic
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
}