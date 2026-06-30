<?php

declare(strict_types=1);

namespace App\Domain\Season\Repository;

use PDO;

/**
 * Season repository.
 *
 * Uses the shared PDO connection with prepared statements. The SQL is reused
 * from the original API so the behaviour and result shapes stay identical.
 */
final class SeasonRepository
{
    public function __construct(private PDO $db)
    {
    }

    public function getCurrentSeasonId(): int
    {
        $row = $this->db->query('SELECT Id FROM Season ORDER BY Id DESC LIMIT 1;')->fetch();

        return (int) $row['Id'];
    }

    public function exists(string $name): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) as num FROM Season WHERE Name = ?');
        $stmt->execute([$name]);

        return $stmt->fetch()['num'] > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getStatistics(int $seasonId): array
    {
        $stmt = $this->db->prepare('SELECT IPLAYER.id, IPLAYER.firstName, IPLAYER.name,
        ISPS.setsPlayed, ISPS.setsWon, ISPS.pointsPlayed,
        ISPS.pointsWon, ISPS.matchesPlayed,
        ISPS.roundsPresent
    FROM Player IPLAYER
    INNER JOIN PlayerSeasonStatistic ISPS ON ISPS.PlayerId = IPLAYER.Id
    WHERE ISPS.SeasonId = ? AND IPLAYER.Member = 1
    ORDER BY ISPS.roundsPresent desc, ISPS.setsWon desc, ISPS.basePoints desc;');
        $stmt->execute([$seasonId]);

        return $stmt->fetchAll();
    }

    public function insertSeason(string $period): int
    {
        $stmt = $this->db->prepare('INSERT INTO Season (Name) VALUES (?)');
        $stmt->execute([$period]);

        return (int) $this->db->lastInsertId();
    }
}
