<?php

namespace Tests\Unit;

use App\Services\GameStatistics;
use PHPUnit\Framework\TestCase;

class GameStatisticsTest extends TestCase
{
    public function test_teams_roteren_per_set(): void
    {
        // Set 1 (P1,P2 thuis): 15-11 — set 2 (P1,P3 thuis): 15-13 — set 3 (P1,P4 thuis): 11-15.
        $statistics = GameStatistics::fromScores(15, 11, 15, 13, 11, 15);

        $this->assertSame([1 => 2, 2 => 2, 3 => 2, 4 => 0], $statistics->setsWon);
        $this->assertSame([1 => 41, 2 => 43, 3 => 41, 4 => 35], $statistics->pointsWon);
        $this->assertSame(80, $statistics->totalPoints);
    }

    public function test_gemiddelden_zijn_eigen_punten_over_drie_sets(): void
    {
        $statistics = GameStatistics::fromScores(15, 11, 15, 13, 11, 15);

        $this->assertEqualsWithDelta((15 + 15 + 11) / 3, $statistics->averages[1], 1e-12);
        $this->assertEqualsWithDelta((15 + 13 + 15) / 3, $statistics->averages[2], 1e-12);
        $this->assertEqualsWithDelta((11 + 15 + 15) / 3, $statistics->averages[3], 1e-12);
        $this->assertEqualsWithDelta((11 + 13 + 11) / 3, $statistics->averages[4], 1e-12);
    }

    public function test_verliezersgemiddelde_is_som_verliezende_setscores_over_drie(): void
    {
        $statistics = GameStatistics::fromScores(15, 11, 15, 13, 11, 15);

        $this->assertEqualsWithDelta((11 + 13 + 11) / 3, $statistics->averageLosing, 1e-12);
    }

    public function test_scores_boven_15_worden_herschaald_naar_15_puntenschaal(): void
    {
        // Set van 24-22: winnaar telt als 15, verliezer als 15/24*22.
        $statistics = GameStatistics::fromScores(24, 22, 15, 10, 15, 10);

        $this->assertEqualsWithDelta((15 + 15 + 15) / 3, $statistics->averages[1], 1e-12);
        $this->assertEqualsWithDelta(15 / 24 * 22, 3 * $statistics->averageLosing - 10 - 10, 1e-12);
        // Ruwe punten blijven ongetrimd.
        $this->assertSame(24 + 15 + 15, $statistics->pointsWon[1]);
    }

    public function test_lege_derde_set_telt_als_setwinst_voor_spelers_2_en_3(): void
    {
        // Legacy-gedrag: 0-0 valt in de else-tak, dus P2 en P3 "winnen" set 3.
        // Set 1: P1,P2 — set 2: P1,P3 — set 3 (0-0): P2,P3.
        $statistics = GameStatistics::fromScores(15, 11, 15, 11, 0, 0);

        $this->assertSame([1 => 2, 2 => 2, 3 => 2, 4 => 0], $statistics->setsWon);
        $this->assertEqualsWithDelta((11 + 11 + 0) / 3, $statistics->averageLosing, 1e-12);
    }

    public function test_gelijke_set_telt_als_winst_voor_away_team(): void
    {
        $statistics = GameStatistics::fromScores(15, 15, 15, 11, 11, 15);

        // Set 1 gelijk (15-15) → P3,P4 winnen. Set 2: P1,P3. Set 3: P2,P3.
        $this->assertSame([1 => 1, 2 => 1, 3 => 3, 4 => 1], $statistics->setsWon);
    }
}
