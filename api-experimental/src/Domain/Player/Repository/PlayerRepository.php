<?php

declare(strict_types=1);

namespace App\Domain\Player\Repository;

use App\Domain\Player\Data\PlayerSummary;
use App\Factory\QueryFactory;
use Cake\Database\Query\SelectQuery;

/**
 * Player repository (CakePHP query builder).
 */
final class PlayerRepository
{
    public function __construct(private QueryFactory $queryFactory)
    {
    }

    /**
     * @return array<int, PlayerSummary>
     */
    public function findMembers(): array
    {
        $rows = $this->queryFactory->newSelect('Player')
            ->select([
                'id' => 'Id',
                'firstName' => 'FirstName',
                'name' => 'Name',
                'gender' => 'Gender',
                'doubleRanking' => 'DoubleRanking',
                'playsCompetition' => 'PlaysCompetition',
                'member' => 'Member',
            ])
            ->where(['Member' => 1])
            ->order(['FirstName' => 'ASC', 'Name' => 'ASC'])
            ->execute()
            ->fetchAll('assoc');

        return array_map(static fn (array $row): PlayerSummary => PlayerSummary::fromRow($row), $rows ?: []);
    }

    public function exists(int $id): bool
    {
        return $this->countById($id, false) > 0;
    }

    public function existsAndIsMember(int $id): bool
    {
        return $this->countById($id, true) > 0;
    }

    private function countById(int $id, bool $onlyMember): int
    {
        $conditions = ['Id' => $id];
        if ($onlyMember) {
            $conditions['Member'] = 1;
        }

        $row = $this->queryFactory->newSelect('Player')
            ->select(['num' => 'COUNT(*)'])
            ->where($conditions)
            ->execute()
            ->fetch('assoc');

        return (int) ($row['num'] ?? 0);
    }

    /**
     * Player identity + season statistics row, or null when absent.
     *
     * @return array<string, mixed>|null
     */
    public function findPlayerWithSeason(int $id, int $seasonId): ?array
    {
        $row = $this->seasonInfoQuery($seasonId)
            ->where(['Player.Id' => $id])
            ->execute()
            ->fetch('assoc');

        return $row === false ? null : $row;
    }

    /**
     * All member rows with season statistics (used by the season calculator).
     *
     * @return array<int, array<string, mixed>>
     */
    public function findAllWithSeason(int $seasonId): array
    {
        return $this->seasonInfoQuery($seasonId)
            ->where(['Player.Member' => 1])
            ->order(['Player.FirstName' => 'ASC', 'Player.Name' => 'ASC'])
            ->execute()
            ->fetchAll('assoc') ?: [];
    }

    public function insertPlayer(
        string $firstName,
        string $name,
        string $gender,
        string $birthDate,
        int $doubleRanking,
        bool $playsCompetition
    ): int {
        return (int) $this->queryFactory
            ->newInsert('Player', ['FirstName', 'Name', 'Gender', 'BirthDate', 'DoubleRanking', 'PlaysCompetition', 'Member'])
            ->values([
                'FirstName' => $firstName,
                'Name' => $name,
                'Gender' => $gender,
                'BirthDate' => $birthDate,
                'DoubleRanking' => $doubleRanking,
                'PlaysCompetition' => $playsCompetition ? 1 : 0,
                'Member' => 1,
            ])
            ->execute()
            ->lastInsertId();
    }

    public function updatePlayer(
        int $id,
        string $firstName,
        string $name,
        string $gender,
        string $birthDate,
        int $doubleRanking,
        bool $playsCompetition
    ): void {
        $this->queryFactory->newUpdate('Player')
            ->set([
                'FirstName' => $firstName,
                'Name' => $name,
                'Gender' => $gender,
                'BirthDate' => $birthDate,
                'DoubleRanking' => $doubleRanking,
                'PlaysCompetition' => $playsCompetition ? 1 : 0,
                'Member' => 1,
            ])
            ->where(['Id' => $id])
            ->execute();
    }

    public function createSeasonStatistic(int $seasonId, int $playerId, float $basePoints): void
    {
        $this->queryFactory
            ->newInsert('PlayerSeasonStatistic', [
                'PlayerId', 'SeasonId', 'BasePoints',
                'SetsPlayed', 'SetsWon', 'PointsPlayed', 'PointsWon', 'MatchesPlayed',
            ])
            ->values([
                'PlayerId' => $playerId,
                'SeasonId' => $seasonId,
                'BasePoints' => $basePoints,
                'SetsPlayed' => 0,
                'SetsWon' => 0,
                'PointsPlayed' => 0,
                'PointsWon' => 0,
                'MatchesPlayed' => 0,
            ])
            ->execute();
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
        $this->queryFactory->newUpdate('PlayerSeasonStatistic')
            ->set([
                'SetsPlayed' => $setsPlayed,
                'SetsWon' => $setsWon,
                'PointsPlayed' => $pointsPlayed,
                'PointsWon' => $pointsWon,
                'MatchesPlayed' => $matchesPlayed,
                'RoundsPresent' => $roundsPresent,
            ])
            ->where(['PlayerId' => $playerId, 'SeasonId' => $seasonId])
            ->execute();
    }

    public function upsertRoundStatistic(int $roundId, int $playerId, float $average): void
    {
        $this->queryFactory
            ->newInsert('PlayerRoundStatistic', ['Average', 'PlayerId', 'RoundId', 'Present', 'DrawnOut'])
            ->values([
                'Average' => $average,
                'PlayerId' => $playerId,
                'RoundId' => $roundId,
                'Present' => 0,
                'DrawnOut' => 0,
            ])
            ->epilog('ON DUPLICATE KEY UPDATE Average = VALUES(Average)')
            ->execute();
    }

    public function upsertAttendance(int $playerId, int $roundId, bool $present, bool $drawnOut): void
    {
        $this->queryFactory
            ->newInsert('PlayerRoundStatistic', ['Present', 'DrawnOut', 'PlayerId', 'RoundId'])
            ->values([
                'Present' => $present ? 1 : 0,
                'DrawnOut' => $drawnOut ? 1 : 0,
                'PlayerId' => $playerId,
                'RoundId' => $roundId,
            ])
            ->epilog('ON DUPLICATE KEY UPDATE Present = VALUES(Present), DrawnOut = VALUES(DrawnOut)')
            ->execute();
    }

    /**
     * Shared select for player identity + season statistics.
     */
    private function seasonInfoQuery(int $seasonId): SelectQuery
    {
        return $this->queryFactory->newSelect('Player')
            ->select([
                'id' => 'Player.Id',
                'firstName' => 'Player.FirstName',
                'name' => 'Player.Name',
                'member' => 'Player.Member',
                'gender' => 'Player.Gender',
                'doubleRanking' => 'Player.DoubleRanking',
                'basePoints' => 'ISPS.BasePoints',
                'setsPlayed' => 'ISPS.SetsPlayed',
                'setsWon' => 'ISPS.SetsWon',
                'pointsPlayed' => 'ISPS.PointsPlayed',
                'pointsWon' => 'ISPS.PointsWon',
                'roundsPresent' => 'ISPS.RoundsPresent',
                'matchesPlayed' => 'ISPS.MatchesPlayed',
            ])
            ->innerJoin(['ISPS' => 'PlayerSeasonStatistic'], 'ISPS.PlayerId = Player.Id')
            ->where(['ISPS.SeasonId' => $seasonId]);
    }
}
