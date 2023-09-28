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
        $query = "SELECT ROW_NUMBER() OVER (ORDER BY ISPS.BasePoints DESC) AS rank,
            IP.id, IP.name, IP.firstName,
            IP.gender, IP.birthDate, ISPS.BasePoints AS average, IP.doubleRanking
        FROM  PlayerSeasonStatistic ISPS
        INNER JOIN Player IP ON IP.id = ISPS.playerId
        WHERE ISPS.seasonId = ? AND IP.member = 1
        ORDER BY rank;";

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
        $query = "SELECT ROW_NUMBER() OVER (ORDER BY ISPS.average DESC) AS rank, ISP.id AS id, ISP.name, ISP.firstName, 
            ISP.gender,  ISP.doubleRanking, ISPS.average
        FROM  PlayerRoundStatistic ISPS
        INNER JOIN `Player` IP ON IP.id = ISPS.playerId
        WHERE ISPS.roundId = ? AND ISP.member = 1
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