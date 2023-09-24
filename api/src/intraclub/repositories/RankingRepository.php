<?php
namespace intraclub\repositories;


class RankingRepository
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
     * Haal ranking op wanneer er nog geen speeldagen zijn
     *
     * @param  int $seasonId
     * @return array rankinginfo
     */
    public function getRankingForNewSeason($seasonId)
    {
        $query = "SELECT ROW_NUMBER() OVER (ORDER BY ISPS.BasePoints DESC) AS Rank,
            IP.Id, IP.Name, IP.FirstName,
            IP.Gender, IP.BirthDate, ISPS.BasePoints AS Average
        FROM  PlayerSeasonStatistic ISPS
        INNER JOIN Player IP ON IP.id = ISPS.PlayerId
        WHERE ISPS.seizoen_id = ? AND IP.Member = 1
        ORDER BY Rank;";

        $stmt = $this->db->prepare($query);
        $stmt->execute([$seasonId]);
        return $stmt->fetchAll();
    }

    /**
     * Haal ranking op na gegeven speeldag
     *
     * @param  int $roundId
     * @return array rankinginfo
     */
    public function getRankingAfterRound($roundId)
    {
        $query = "SELECT ROW_NUMBER() OVER (ORDER BY ISPS.gemiddelde DESC) AS rank, ISP.id AS id, ISP.naam AS name, ISP.voornaam as firstName, 
            ISP.geslacht AS gender, ISP.is_veteraan as veteran, ISP.klassement AS ranking, ISP.jeugd as youth, ISPS.gemiddelde AS average
        FROM  intra_spelerperspeeldag ISPS
        INNER JOIN intra_spelers ISP ON ISP.id = ISPS.speler_id
        WHERE ISPS.speeldag_id = ? AND ISP.is_lid = 1
        ORDER BY rank;";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$roundId]);
        return $stmt->fetchAll();
    }

    /**
     * Haal rankinggeschiedenis op voor een speler in een seizoen
     *
     * @param  int $playerId
     * @param  int $seasonId
     * @return array rankinginfo
     */
    public function getRankingHistoryByPlayerAndSeason($playerId, $seasonId)
    {
        $query = "SELECT * FROM (
                    SELECT ROW_NUMBER() OVER (PARTITION BY ISPS.speeldag_id ORDER BY ISPS.gemiddelde DESC) AS rank, 
                    ISPS.speler_id AS id, ISPS.gemiddelde AS average, ISPS.speeldag_id, ISPEEL.speeldagnummer, ISPEEL.datum 
                    FROM intra_spelerperspeeldag ISPS 
                    INNER JOIN intra_speeldagen ISPEEL ON ISPEEL.id = ISPS.speeldag_id 
                    WHERE ISPEEL.seizoen_id = ? 
                    ORDER BY ISPEEL.Id, rank ) AS FullRanking 
                    WHERE id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$seasonId, $playerId]);
        return $stmt->fetchAll();
    }
}