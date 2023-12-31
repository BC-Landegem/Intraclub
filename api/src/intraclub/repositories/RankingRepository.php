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
            IP.gender, IP.birthDate, ISPS.BasePoints AS average, IP.doubleRanking, IP.birthDate, IP.playsCompetition
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
        $query = "SELECT ROW_NUMBER() OVER (ORDER BY ISPS.average DESC) AS rank, IP.id AS id, IP.name, IP.firstName, 
            IP.gender,  IP.doubleRanking, ISPS.average, IP.birthDate, IP.playsCompetition
        FROM  PlayerRoundStatistic ISPS
        INNER JOIN `Player` IP ON IP.id = ISPS.playerId
        WHERE ISPS.roundId = ? AND IP.member = 1
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
                    SELECT ROW_NUMBER() OVER (PARTITION BY ISPS.roundId ORDER BY ISPS.average DESC) AS rank, 
                    ISPS.playerId AS id, ISPS.average, ISPS.roundId, ISPEEL.number, ISPEEL.date 
                    FROM `PlayerRoundStatistic` ISPS 
                    INNER JOIN `Round` ISPEEL ON ISPEEL.id = ISPS.roundId 
                    WHERE ISPEEL.seasonId = ? 
                    ORDER BY ISPEEL.Id, rank ) AS FullRanking 
                    WHERE id = ?";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$seasonId, $playerId]);
        return $stmt->fetchAll();
    }
}