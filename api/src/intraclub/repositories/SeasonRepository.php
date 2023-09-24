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
     * @return array seizoen
     */
    public function getCurrentSeasonId(): int
    {
        $currentSeason = $this->db->query("SELECT id, seizoen as season FROM intra_seizoen ORDER BY id DESC LIMIT 1;")->fetch();
        return $currentSeason["id"];
    }
    /**
     * Controle of er een seizoen bestaat met zelfde naam
     *
     * @param  string $name
     * @return bool true indien naam reeds bestaat
     */
    public function exists($name)
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) as num FROM Season WHERE [Name] = ? ");
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


}