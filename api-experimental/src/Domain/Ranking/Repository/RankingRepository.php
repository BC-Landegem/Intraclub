<?php

declare(strict_types=1);

namespace App\Domain\Ranking\Repository;

use PDO;

/**
 * Ranking repository.
 *
 * Uses the shared PDO connection with prepared statements. The SQL is reused
 * from the original API so the behaviour and result shapes stay identical.
 */
final class RankingRepository
{
    public function __construct(private PDO $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRankingForNewSeason(int $seasonId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ROW_NUMBER() OVER (ORDER BY ISPS.BasePoints DESC) AS rank,
                IP.id, IP.name, IP.firstName,
                IP.gender, IP.birthDate, ISPS.BasePoints AS average, IP.doubleRanking, IP.birthDate, IP.playsCompetition
            FROM  PlayerSeasonStatistic ISPS
            INNER JOIN Player IP ON IP.id = ISPS.playerId
            WHERE ISPS.seasonId = ? AND IP.member = 1
            ORDER BY rank;'
        );
        $stmt->execute([$seasonId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRankingAfterRound(int $roundId): array
    {
        $stmt = $this->db->prepare(
            'SELECT ROW_NUMBER() OVER (ORDER BY ISPS.average DESC) AS rank, IP.id AS id, IP.name, IP.firstName,
                IP.gender,  IP.doubleRanking, ISPS.average, IP.birthDate, IP.playsCompetition
            FROM  PlayerRoundStatistic ISPS
            INNER JOIN `Player` IP ON IP.id = ISPS.playerId
            WHERE ISPS.roundId = ? AND IP.member = 1
            ORDER BY rank;'
        );
        $stmt->execute([$roundId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getRankingHistoryByPlayerAndSeason(int $playerId, int $seasonId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM (
                SELECT ROW_NUMBER() OVER (PARTITION BY ISPS.roundId ORDER BY ISPS.average DESC) AS rank,
                ISPS.playerId AS id, ISPS.average, ISPS.roundId, ISPEEL.number, ISPEEL.date
                FROM `PlayerRoundStatistic` ISPS
                INNER JOIN `Round` ISPEEL ON ISPEEL.id = ISPS.roundId
                WHERE ISPEEL.seasonId = ?
                ORDER BY ISPEEL.Id, rank ) AS FullRanking
                WHERE id = ?'
        );
        $stmt->execute([$seasonId, $playerId]);

        return $stmt->fetchAll();
    }
}
