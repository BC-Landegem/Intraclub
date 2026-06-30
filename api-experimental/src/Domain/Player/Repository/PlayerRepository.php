<?php

declare(strict_types=1);

namespace App\Domain\Player\Repository;

use PDO;

/**
 * Player repository.
 *
 * Uses the shared PDO connection with prepared statements. The SQL is reused
 * from the original API so the behaviour and result shapes stay identical.
 */
final class PlayerRepository
{
    private string $playerQuery = 'SELECT IPLAYER.id, IPLAYER.firstName, IPLAYER.name,
        IPLAYER.playsCompetition, IPLAYER.member,
        IPLAYER.gender, IPLAYER.doubleRanking
        FROM Player IPLAYER';

    private string $playerWithSeasonInfoQuery = '
        SELECT IPLAYER.id, IPLAYER.firstName, IPLAYER.name, IPLAYER.member,
            IPLAYER.gender, IPLAYER.doubleRanking,
            ISPS.basePoints, ISPS.setsPlayed, ISPS.setsWon, ISPS.pointsPlayed,
            ISPS.pointsWon, ISPS.roundsPresent, ISPS.matchesPlayed
            FROM `Player` IPLAYER
            INNER JOIN PlayerSeasonStatistic ISPS ON ISPS.playerId = IPLAYER.id
            WHERE ISPS.seasonId = ?';

    public function __construct(private PDO $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAll(bool $onlyMembers = true): array
    {
        $query = $this->playerQuery;
        if ($onlyMembers) {
            $query .= ' WHERE IPLAYER.Member = true';
        }
        $query .= ' ORDER BY FirstName, Name';

        return $this->db->query($query)->fetchAll();
    }

    public function exists(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT COUNT(*) as num FROM Player WHERE Id = ?');
        $stmt->execute([$id]);

        return $stmt->fetch()['num'] > 0;
    }

    public function existsAndIsMember(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT Id, Member FROM Player WHERE Id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row && $row['Member'] == 1;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getAllWithSeasonInfo(int $seasonId, bool $onlyMembers = true): array
    {
        $query = $this->playerWithSeasonInfoQuery;
        if ($onlyMembers) {
            $query .= ' AND IPLAYER.member = true';
        }
        $query .= ' ORDER BY firstName, name';

        $stmt = $this->db->prepare($query);
        $stmt->execute([$seasonId]);

        return $stmt->fetchAll();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getByIdWithSeasonInfo(int $id, int $seasonId): ?array
    {
        $stmt = $this->db->prepare($this->playerWithSeasonInfoQuery . ' AND IPLAYER.id = ?');
        $stmt->execute([$seasonId, $id]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Read the possible genders from the Player.gender enum column.
     *
     * @return array<int, string>
     */
    public function getPossibleGenders(): array
    {
        $stmt = $this->db->prepare("SHOW COLUMNS FROM Player WHERE field = 'gender'");
        $stmt->execute();
        $row = $stmt->fetch();

        $enum = [];
        foreach (explode("','", substr((string) $row['Type'], 6, -2)) as $value) {
            $enum[] = $value;
        }

        return $enum;
    }

    public function insertPlayer(
        string $firstName,
        string $name,
        string $gender,
        string $birthDate,
        int $doubleRanking,
        bool $playsCompetition
    ): int {
        $stmt = $this->db->prepare('INSERT INTO Player
            SET
                FirstName = :firstName,
                `Name` = :lastName,
                Gender = :gender,
                BirthDate = :birthDate,
                DoubleRanking = :doubleRanking,
                PlaysCompetition = :playsCompetition,
                Member = 1');

        $stmt->execute([
            'firstName' => $firstName,
            'lastName' => $name,
            'gender' => $gender,
            'birthDate' => $birthDate,
            'doubleRanking' => $doubleRanking,
            'playsCompetition' => $playsCompetition ? 1 : 0,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update an existing player.
     *
     * Note: the original API updated a non-existing `intra_spelers` table with
     * Dutch column names; this corrected version targets the real Player table.
     */
    public function updatePlayer(
        int $id,
        string $firstName,
        string $name,
        string $gender,
        string $birthDate,
        int $doubleRanking,
        bool $playsCompetition
    ): void {
        $stmt = $this->db->prepare('UPDATE Player
            SET
                FirstName = :firstName,
                `Name` = :lastName,
                Gender = :gender,
                BirthDate = :birthDate,
                DoubleRanking = :doubleRanking,
                PlaysCompetition = :playsCompetition,
                Member = 1
            WHERE Id = :id');

        $stmt->execute([
            'firstName' => $firstName,
            'lastName' => $name,
            'gender' => $gender,
            'birthDate' => $birthDate,
            'doubleRanking' => $doubleRanking,
            'playsCompetition' => $playsCompetition ? 1 : 0,
            'id' => $id,
        ]);
    }

    public function createSeasonStatistic(int $seasonId, int $playerId, float $basePoints): void
    {
        $stmt = $this->db->prepare('INSERT INTO PlayerSeasonStatistic
            SET
                PlayerId = :playerId,
                SeasonId = :seasonId,
                BasePoints = :basePoints,
                SetsPlayed = 0,
                SetsWon = 0,
                PointsPlayed = 0,
                PointsWon = 0,
                MatchesPlayed = 0');

        $stmt->execute([
            'playerId' => $playerId,
            'seasonId' => $seasonId,
            'basePoints' => $basePoints,
        ]);
    }

    public function updateSeasonStatistic(
        int $seasonId,
        int $playerId,
        int $setsPlayed,
        int $setsWon,
        int $pointsPlayed,
        int $pointsWon,
        int $roundsPresent,
        int $matchesPlayed
    ): void {
        $stmt = $this->db->prepare('UPDATE PlayerSeasonStatistic
            SET
                SetsPlayed = :setsPlayed,
                SetsWon = :setsWon,
                PointsPlayed = :pointsPlayed,
                PointsWon = :pointsWon,
                MatchesPlayed = :matchesPlayed,
                RoundsPresent = :roundsPresent
            WHERE PlayerId = :playerId AND SeasonId = :seasonId');

        $stmt->execute([
            'setsPlayed' => $setsPlayed,
            'setsWon' => $setsWon,
            'pointsPlayed' => $pointsPlayed,
            'pointsWon' => $pointsWon,
            'matchesPlayed' => $matchesPlayed,
            'roundsPresent' => $roundsPresent,
            'playerId' => $playerId,
            'seasonId' => $seasonId,
        ]);
    }

    public function insertOrUpdateRoundStatistic(int $roundId, int $playerId, float $average): void
    {
        $stmt = $this->db->prepare('INSERT INTO PlayerRoundStatistic
            SET
                Average = :average,
                PlayerId = :playerId,
                RoundId = :roundId,
                Present = 0,
                DrawnOut = 0
            ON DUPLICATE KEY UPDATE
                Average = :average');

        $stmt->execute([
            'average' => $average,
            'playerId' => $playerId,
            'roundId' => $roundId,
        ]);
    }

    public function insertOrUpdateAttendanceData(int $playerId, int $roundId, bool $present, bool $drawnOut): void
    {
        $stmt = $this->db->prepare('INSERT INTO PlayerRoundStatistic
            SET
                Present = :present,
                DrawnOut = :drawnOut,
                PlayerId = :playerId,
                RoundId = :roundId
            ON DUPLICATE KEY UPDATE
                Present = :present,
                DrawnOut = :drawnOut');

        $stmt->execute([
            'present' => $present ? 1 : 0,
            'drawnOut' => $drawnOut ? 1 : 0,
            'playerId' => $playerId,
            'roundId' => $roundId,
        ]);
    }
}
