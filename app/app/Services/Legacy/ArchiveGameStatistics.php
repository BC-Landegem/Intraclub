<?php

namespace App\Services\Legacy;

use App\Services\GameStatistics;

/**
 * Statistieken van één archiefwedstrijd: vaste teams, best-of-3.
 *
 * Dit is de tegenhanger van {@see GameStatistics}, dat vier spelers met
 * per set roterende teams veronderstelt. In het oude format speelt team 1 alle sets
 * tegen team 2, en blijft de derde set leeg zodra een team er twee gewonnen heeft.
 *
 * De regels zijn afgeleid uit de bewaarde eindstanden van de comp-generatie en
 * reproduceren die exact voor drie van de vier seizoenen (55/55, 82/82 en 79/79
 * spelers); het vierde seizoen heeft geen betrouwbare bewaarde stand.
 */
readonly class ArchiveGameStatistics
{
    private function __construct(
        public float $team1Average,
        public float $team2Average,
        public int $setsPlayed,
    ) {}

    public static function fromGame(object $game): self
    {
        $sets = [];

        foreach ([['set1_home', 'set1_away'], ['set2_home', 'set2_away'], ['set3_home', 'set3_away']] as [$thuis, $uit]) {
            if ($game->{$thuis} === null || $game->{$uit} === null) {
                continue;
            }

            $thuisScore = (int) $game->{$thuis};
            $uitScore = (int) $game->{$uit};

            // Een niet-gespeelde set staat in de oude data als 0-0 in plaats van leeg.
            if ($thuisScore === 0 && $uitScore === 0) {
                continue;
            }

            $sets[] = [$thuisScore, $uitScore];
        }

        if ($sets === []) {
            return new self(0.0, 0.0, 0);
        }

        $team1 = 0.0;
        $team2 = 0.0;

        foreach ($sets as [$thuisScore, $uitScore]) {
            $team1 += self::trim($thuisScore, $uitScore);
            $team2 += self::trim($uitScore, $thuisScore);
        }

        return new self($team1 / count($sets), $team2 / count($sets), count($sets));
    }

    /** Het gemiddelde van het gevraagde team; 1 of 2. */
    public function averageFor(int $team): float
    {
        return $team === 1 ? $this->team1Average : $this->team2Average;
    }

    /**
     * Herschaal een setscore naar een 21-puntenschaal wanneer er voorbij 21 werd gespeeld
     * (verlengingen), zodat elke set even zwaar doorweegt in het gemiddelde. Identiek aan
     * de regel in {@see GameStatistics}.
     */
    private static function trim(int $score, int $opponentScore): float
    {
        return ($score > 21 || $opponentScore > 21)
            ? 21 / max($score, $opponentScore) * $score
            : (float) $score;
    }
}
