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
        $rounds = $this->roundRepository->findBySeason($currentSeasonId);
        $averageLosersArray = [];
        $roundNumber = 1;

        foreach ($rounds as $round) {
            $averageLosers = 0;
            $totalMatches = 0;
            foreach ($this->matchRepository->findByRound($round->id) as $m) {
                $score = MatchCalculator::calculate(
                    $m->set1->home,
                    $m->set1->away,
                    $m->set2->home,
                    $m->set2->away,
                    $m->set3->home,
                    $m->set3->away
                );
                $averageLosers += $score['averageLosing'];
                $totalMatches++;
            }
            $avg = $totalMatches > 0 ? $averageLosers / $totalMatches : 0;
            $this->roundRepository->updateAverageAbsent($round->id, (float) $avg);
            $averageLosersArray[$roundNumber] = $avg;
            $roundNumber++;
        }

        $lastRoundNumber = $roundNumber - 1;
        $allPlayers = $this->playerRepository->findAllWithSeason($currentSeasonId);

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
            $playerId = (int) $player['id'];

            foreach ($this->matchRepository->findBySeasonAndPlayer($currentSeasonId, $playerId) as $m) {
                while ($m->roundNumber > $roundNumber) {
                    $resultArray[$roundNumber] = $averageLosersArray[$roundNumber];
                    $roundNumber++;
                }
                if ($roundNumber > $m->roundNumber) {
                    // multiple games on same round, skip
                } elseif ($roundNumber === $m->roundNumber) {
                    $st = MatchCalculator::calculate(
                        $m->set1->home,
                        $m->set1->away,
                        $m->set2->home,
                        $m->set2->away,
                        $m->set3->home,
                        $m->set3->away
                    );
                    $seasonStats['roundsPresent']++;
                    $seasonStats['matchesPlayed']++;
                    $seasonStats['pointsPlayed'] += $st['totalPoints'];
                    $seasonStats['setsPlayed'] += 3;
                    switch ($playerId) {
                        case $m->homePlayer1->id:
                            $resultArray[$roundNumber] = $st['averagePlayer1'];
                            $seasonStats['setsWon'] += $st['setsWonPlayer1'];
                            $seasonStats['pointsWon'] += $st['pointsWonPlayer1'];
                            break;
                        case $m->homePlayer2->id:
                            $resultArray[$roundNumber] = $st['averagePlayer2'];
                            $seasonStats['setsWon'] += $st['setsWonPlayer2'];
                            $seasonStats['pointsWon'] += $st['pointsWonPlayer2'];
                            break;
                        case $m->awayPlayer1->id:
                            $resultArray[$roundNumber] = $st['averagePlayer3'];
                            $seasonStats['setsWon'] += $st['setsWonPlayer3'];
                            $seasonStats['pointsWon'] += $st['pointsWonPlayer3'];
                            break;
                        case $m->awayPlayer2->id:
                            $resultArray[$roundNumber] = $st['averagePlayer4'];
                            $seasonStats['setsWon'] += $st['setsWonPlayer4'];
                            $seasonStats['pointsWon'] += $st['pointsWonPlayer4'];
                            break;
                    }
                    $roundNumber++;
                }
            }

            while ($roundNumber <= $lastRoundNumber) {
                $resultArray[$roundNumber] = $averageLosersArray[$roundNumber];
                $roundNumber++;
            }

            foreach ($rounds as $round) {
                $sum = 0;
                $total = 0;
                for ($j = 0; $j <= $round->number; $j++) {
                    $sum += $resultArray[$j];
                    $total++;
                }
                $averageRound = $sum / $total;
                $this->playerRepository->upsertRoundStatistic($round->id, $playerId, (float) $averageRound);
            }

            $this->playerRepository->updateSeasonStatistic(
                $currentSeasonId,
                $playerId,
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
