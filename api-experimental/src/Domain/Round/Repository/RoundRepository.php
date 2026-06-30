<?php

declare(strict_types=1);

namespace App\Domain\Round\Repository;

use PDO;

/**
 * Round (speeldag) repository.
 *
 * Uses the shared PDO connection with prepared statements. The SQL is reused
 * from the original API so the behaviour and result shapes stay identical.
 */
final class RoundRepository
{
    private string $roundQuery = 'SELECT RND.id, RND.number, ROUND(RND.AverageAbsent,2) AS averageAbsent,
RND.date, RND.calculated, (SELECT COUNT(MT.id) FROM `Match` MT where MT.RoundId = RND.Id) as matches
FROM Round RND';

    public function __construct(private PDO $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllBySeason(int $seasonId): array
    {
        $stmt = $this->db->prepare($this->roundQuery . ' WHERE RND.seasonId = ? ORDER BY RND.id ASC;');
        $stmt->execute([$seasonId]);

        return $stmt->fetchAll();
    }

    public function insertRound(int $seasonId, string $date, int $roundNumber): void
    {
        $stmt = $this->db->prepare('INSERT INTO Round (SeasonId, Date, Number, AverageAbsent, Calculated, DrawClosed)
            VALUES (:seasonId, :date, :roundNumber, 0, 0, 0)');

        $stmt->execute([
            'seasonId' => $seasonId,
            'date' => $date,
            'roundNumber' => $roundNumber,
        ]);
    }

    public function updateAverageAbsent(int $id, float $averageAbsent): void
    {
        $stmt = $this->db->prepare('UPDATE `Round` SET AverageAbsent = ?, Calculated = 1 WHERE Id = ?');
        $stmt->execute([$averageAbsent, $id]);
    }

    public function existsWithDate(string $date): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) as num FROM Round WHERE `Date` = ?');
        $stmt->execute([$date]);

        return $stmt->fetch()['num'] > 0;
    }

    public function exists(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) as num FROM Round WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch()['num'] > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getById(int $id): ?array
    {
        $stmt = $this->db->prepare($this->roundQuery . ' WHERE RND.Id=?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getBySeasonAndNumber(int $seasonId, int $number): ?array
    {
        $stmt = $this->db->prepare($this->roundQuery . ' WHERE RND.seasonId = :seasonId and RND.number = :roundNumber;');
        $stmt->execute([
            'seasonId' => $seasonId,
            'roundNumber' => $number,
        ]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLast(int $seasonId): ?array
    {
        $stmt = $this->db->prepare($this->roundQuery . ' WHERE RND.SeasonId=? ORDER BY RND.Number DESC LIMIT 1;');
        $stmt->execute([$seasonId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getLastCalculated(int $seasonId): ?array
    {
        $stmt = $this->db->prepare(
            $this->roundQuery . ' WHERE RND.SeasonId=? AND RND.Calculated = 1 ORDER BY RND.Number DESC LIMIT 1;'
        );
        $stmt->execute([$seasonId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getWithMatches(int $id): array
    {
        $stmt = $this->db->prepare('SELECT RND.id, RND.number, ROUND(RND.averageAbsent,2) AS averageAbsent,
    RND.date, RND.calculated, set1Home,set1Away, set2Home, set2Away, set3Home, set3Away,
    PL1H.Id as player1Id, PL1H.firstName AS player1FirstName, PL1H.name AS player1Name,
    PL2H.Id as player2Id, PL2H.firstName AS player2FirstName, PL2H.name AS player2Name,
    PL1A.Id as player3Id, PL1A.firstName AS player3FirstName, PL1A.name AS player3Name,
    PL2A.Id as player4Id, PL2A.firstName AS player4FirstName, PL2A.name AS player4Name
    FROM `Round` RND
    INNER JOIN `Match` MT ON MT.roundId = RND.id
    INNER JOIN Player PL1H ON PL1H.id =  MT.Player1Id
    INNER JOIN Player PL2H ON PL2H.id =  MT.Player2Id
    INNER JOIN Player PL1A ON PL1A.id =  MT.Player3Id
    INNER JOIN Player PL2A ON PL2A.id =  MT.Player4Id WHERE RND.id=?
    ORDER BY MT.Id ASC;');
        $stmt->execute([$id]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAvailabilityData(int $id): array
    {
        $stmt = $this->db->prepare(
            'SELECT playerId, present, drawnOut, average FROM `PlayerRoundStatistic` WHERE roundId = ?'
        );
        $stmt->execute([$id]);

        return $stmt->fetchAll();
    }
}
