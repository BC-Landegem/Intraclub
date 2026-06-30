<?php

declare(strict_types=1);

namespace App\Domain\Match\Repository;

use PDO;

/**
 * Match repository.
 *
 * Uses the shared PDO connection with prepared statements. The SQL is reused
 * verbatim from the original API so the behaviour and result shapes stay
 * identical.
 */
final class MatchRepository
{
    private string $matchQuery = 'SELECT MT.id, MT.roundId, RND.number as roundNumber,
    MT.set1Home, MT.set1Away, MT.set2Home, MT.set2Away,
    MT.set3Home, MT.set3Away,
    PL1H.Id as player1Id, PL1H.FirstName AS player1FirstName, PL1H.Name AS player1Name,
    PL2H.Id as player2Id, PL2H.FirstName AS player2FirstName, PL2H.Name AS player2Name,
    PL1A.Id as player3Id, PL1A.FirstName AS player3FirstName, PL1A.Name AS player3Name,
    PL2A.Id as player4Id, PL2A.FirstName AS player4FirstName, PL2A.Name AS player4Name
FROM `Match` MT
INNER JOIN `Round` RND ON RND.id = MT.roundId
INNER JOIN Player PL1H ON PL1H.id =  MT.player1Id
INNER JOIN Player PL2H ON PL2H.id =  MT.player2Id
INNER JOIN Player PL1A ON PL1A.id =  MT.player3Id
INNER JOIN Player PL2A ON PL2A.id =  MT.player4Id';

    public function __construct(private PDO $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllBySeasonId(int $seasonId): array
    {
        $stmt = $this->db->prepare($this->matchQuery . ' WHERE ISEASON.Id=?');
        $stmt->execute([$seasonId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllByRoundId(int $roundId): array
    {
        $stmt = $this->db->prepare($this->matchQuery . ' WHERE RND.Id=?');
        $stmt->execute([$roundId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllBySeasonAndPlayerId(int $seasonId, int $playerId): array
    {
        $query = 'SELECT MT.id, set1Home, set1Away, set2Home, set2Away, set3Home, set3Away,
    PL1.Id as player1Id, PL1.FirstName AS player1FirstName, PL1.Name AS player1Name,
    PL2.Id as player2Id, PL2.FirstName AS player2FirstName, PL2.Name AS player2Name,
    PL3.Id as player3Id, PL3.FirstName AS player3FirstName, PL3.Name AS player3Name,
    PL4.Id as player4Id, PL4.FirstName AS player4FirstName, PL4.Name AS player4Name,
    RND.Id as roundId, RND.Number AS roundNumber
    FROM `Match` MT
    INNER JOIN Round RND ON RND.id = MT.RoundId
    INNER JOIN Player PL1 ON PL1.id =  MT.player1Id
    INNER JOIN Player PL2 ON PL2.id =  MT.player2Id
    INNER JOIN Player PL3 ON PL3.id =  MT.player3Id
    INNER JOIN Player PL4 ON PL4.id =  MT.player4Id
    WHERE (
            (
                PL1.Id  = ? OR
                PL2.Id  = ? OR
                PL3.Id = ? OR
                PL4.Id = ?
            ) AND RND.SeasonId = ?
        )
    ORDER BY MT.Id ASC;';

        $stmt = $this->db->prepare($query);
        $stmt->execute([$playerId, $playerId, $playerId, $playerId, $seasonId]);

        return $stmt->fetchAll();
    }

    public function create(
        int $roundId,
        int $playerId1,
        int $playerId2,
        int $playerId3,
        int $playerId4
    ): int {
        $stmt = $this->db->prepare('INSERT INTO `Match`
            (RoundId, Player1Id, Player2Id, Player3Id, Player4Id,
                Set1Home, Set1Away, Set2Home, Set2Away, Set3Home, Set3Away)
            VALUES
            (:roundId, :player1Id, :player2Id, :player3Id, :player4Id, 0, 0, 0, 0, 0, 0)');

        $stmt->execute([
            'roundId' => $roundId,
            'player1Id' => $playerId1,
            'player2Id' => $playerId2,
            'player3Id' => $playerId3,
            'player4Id' => $playerId4,
        ]);

        return (int) $this->db->lastInsertId();
    }

    public function update(
        int $id,
        int $set1Home,
        int $set1Away,
        int $set2Home,
        int $set2Away,
        int $set3Home,
        int $set3Away
    ): bool {
        $stmt = $this->db->prepare('UPDATE `Match`
            SET
                Set1Home = :set1Home,
                Set1Away = :set1Away,
                Set2Home = :set2Home,
                Set2Away = :set2Away,
                Set3Home = :set3Home,
                Set3Away = :set3Away
            WHERE Id = :id');

        return $stmt->execute([
            'set1Home' => $set1Home,
            'set1Away' => $set1Away,
            'set2Home' => $set2Home,
            'set2Away' => $set2Away,
            'set3Home' => $set3Home,
            'set3Away' => $set3Away,
            'id' => $id,
        ]);
    }

    public function exists(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) as num FROM `Match` WHERE id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch()['num'] > 0;
    }
}
