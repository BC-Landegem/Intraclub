<?php

declare(strict_types=1);

namespace App\Domain\Season\Service;

use App\Domain\Match\Repository\MatchRepository;
use App\Domain\Player\Repository\PlayerRepository;
use App\Domain\Round\Repository\RoundRepository;
use App\Domain\Season\Repository\SeasonRepository;
use App\Support\MatchCalculator;

/**
 * Computes interim standings for the current season.
 *
 * Ported faithfully from the original Slim 3 API so the computed standings
 * remain identical.
 */
final class SeasonCalculator
{
    public function __construct(
        private SeasonRepository $seasonRepository,
        private RoundRepository $roundRepository,
        private MatchRepository $matchRepository,
        private PlayerRepository $playerRepository
    ) {
    }

    public function calculateCurrentSeason(): void
    {
        $currentSeasonId = $this->seasonRepository->getCurrentSeasonId();
        $roundsOfCurrentSeason = $this->roundRepository->getAllBySeason($currentSeasonId);
        $averageLosersArray = [];
        $roundNumber = 1;

        foreach ($roundsOfCurrentSeason as $round) {
            $averageLosers = 0;
            $totalMatches = 0;
            $matches = $this->matchRepository->getAllByRoundId((int) $round['id']);
            foreach ($matches as $match) {
                $score = MatchCalculator::calculate(
                    (int) $match['set1Home'],
                    (int) $match['set1Away'],
                    (int) $match['set2Home'],
                    (int) $match['set2Away'],
                    (int) $match['set3Home'],
                    (int) $match['set3Away']
                );
                $averageLosers += $score['averageLosing'];
                $totalMatches++;
            }
            $averageLosingCurrentRound = $averageLosers / $totalMatches;
            $this->roundRepository->updateAverageAbsent((int) $round['id'], (float) $averageLosingCurrentRound);
            $averageLosersArray[$roundNumber] = $averageLosingCurrentRound;
            $roundNumber++;
        }

        $lastRoundNumber = $roundNumber - 1;
        $allPlayers = $this->playerRepository->getAllWithSeasonInfo($currentSeasonId, true);

        foreach ($allPlayers as $player) {
            $resultArray = [];
            $resultArray[0] = $player['basePoints'];
            $roundNumber = 1;
            $seasonStats = [
                'setsPlayed' => 0,
                'setsWon' => 0,
                'roundsPresent' => 0,
                'matchesPlayed' => 0,
                'pointsPlayed' => 0,
                'pointsWon' => 0,
            ];
            $matchesCurrentPlayer = $this->matchRepository->getAllBySeasonAndPlayerId(
                $currentSeasonId,
                (int) $player['id']
            );

            foreach ($matchesCurrentPlayer as $matchCurrentPlayer) {
                while ((int) $matchCurrentPlayer['roundNumber'] > $roundNumber) {
                    $resultArray[$roundNumber] = $averageLosersArray[$roundNumber];
                    $roundNumber++;
                }
                if ($roundNumber > (int) $matchCurrentPlayer['roundNumber']) {
                    // multiple games on same round, skip
                } elseif ($roundNumber == (int) $matchCurrentPlayer['roundNumber']) {
                    $matchStatistics = MatchCalculator::calculate(
                        (int) $matchCurrentPlayer['set1Home'],
                        (int) $matchCurrentPlayer['set1Away'],
                        (int) $matchCurrentPlayer['set2Home'],
                        (int) $matchCurrentPlayer['set2Away'],
                        (int) $matchCurrentPlayer['set3Home'],
                        (int) $matchCurrentPlayer['set3Away']
                    );
                    $seasonStats['roundsPresent']++;
                    $seasonStats['matchesPlayed']++;
                    $seasonStats['pointsPlayed'] += $matchStatistics['totalPoints'];
                    $seasonStats['setsPlayed'] += 3;
                    switch ((int) $player['id']) {
                        case (int) $matchCurrentPlayer['player1Id']:
                            $resultArray[$roundNumber] = $matchStatistics['averagePlayer1'];
                            $seasonStats['setsWon'] += $matchStatistics['setsWonPlayer1'];
                            $seasonStats['pointsWon'] += $matchStatistics['pointsWonPlayer1'];
                            break;
                        case (int) $matchCurrentPlayer['player2Id']:
                            $resultArray[$roundNumber] = $matchStatistics['averagePlayer2'];
                            $seasonStats['setsWon'] += $matchStatistics['setsWonPlayer2'];
                            $seasonStats['pointsWon'] += $matchStatistics['pointsWonPlayer2'];
                            break;
                        case (int) $matchCurrentPlayer['player3Id']:
                            $resultArray[$roundNumber] = $matchStatistics['averagePlayer3'];
                            $seasonStats['setsWon'] += $matchStatistics['setsWonPlayer3'];
                            $seasonStats['pointsWon'] += $matchStatistics['pointsWonPlayer3'];
                            break;
                        case (int) $matchCurrentPlayer['player4Id']:
                            $resultArray[$roundNumber] = $matchStatistics['averagePlayer4'];
                            $seasonStats['setsWon'] += $matchStatistics['setsWonPlayer4'];
                            $seasonStats['pointsWon'] += $matchStatistics['pointsWonPlayer4'];
                            break;
                    }
                    $roundNumber++;
                }
            }

            while ($roundNumber <= $lastRoundNumber) {
                $resultArray[$roundNumber] = $averageLosersArray[$roundNumber];
                $roundNumber++;
            }

            foreach ($roundsOfCurrentSeason as $round) {
                $sumOfAveragePerRound = 0;
                $totalRounds = 0;
                for ($j = 0; $j <= $round['number']; $j++) {
                    $sumOfAveragePerRound += $resultArray[$j];
                    $totalRounds++;
                }
                $averageRound = $sumOfAveragePerRound / ($totalRounds);
                $this->playerRepository->insertOrUpdateRoundStatistic(
                    (int) $round['id'],
                    (int) $player['id'],
                    (float) $averageRound
                );
            }

            $this->playerRepository->updateSeasonStatistic(
                $currentSeasonId,
                (int) $player['id'],
                (int) $seasonStats['setsPlayed'],
                (int) $seasonStats['setsWon'],
                (int) $seasonStats['pointsPlayed'],
                (int) $seasonStats['pointsWon'],
                (int) $seasonStats['roundsPresent'],
                (int) $seasonStats['matchesPlayed']
            );
        }
    }
}
