<?php

namespace Tests\Unit;

use App\Enums\PointsPerSet;
use App\Services\GameStatistics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Tests\Support\SeasonFormat;

class GameStatisticsTest extends TestCase
{
    /** @return array<string, array{0: SeasonFormat}> */
    public static function formats(): array
    {
        return SeasonFormat::provider();
    }

    #[DataProvider('formats')]
    public function test_teams_roteren_per_set(SeasonFormat $format): void
    {
        $win = $format->win();
        $lose = $format->lose();
        $close = $format->loseClose();
        // Set 1 (P1,P2 thuis): win-lose — set 2 (P1,P3 thuis): win-close — set 3 (P1,P4 thuis): lose-win.
        $statistics = GameStatistics::fromScores($win, $lose, $win, $close, $lose, $win, $format->value());

        $this->assertSame([1 => 2, 2 => 2, 3 => 2, 4 => 0], $statistics->setsWon);
        $this->assertSame([
            1 => $win + $win + $lose,
            2 => $win + $close + $win,
            3 => $lose + $win + $win,
            4 => $lose + $close + $lose,
        ], $statistics->pointsWon);
        $this->assertSame($win + $lose + $win + $close + $lose + $win, $statistics->totalPoints);
    }

    #[DataProvider('formats')]
    public function test_gemiddelden_zijn_eigen_punten_over_drie_sets(SeasonFormat $format): void
    {
        $win = $format->win();
        $lose = $format->lose();
        $close = $format->loseClose();
        $statistics = GameStatistics::fromScores($win, $lose, $win, $close, $lose, $win, $format->value());

        $this->assertEqualsWithDelta(($win + $win + $lose) / 3, $statistics->averages[1], 1e-12);
        $this->assertEqualsWithDelta(($win + $close + $win) / 3, $statistics->averages[2], 1e-12);
        $this->assertEqualsWithDelta(($lose + $win + $win) / 3, $statistics->averages[3], 1e-12);
        $this->assertEqualsWithDelta(($lose + $close + $lose) / 3, $statistics->averages[4], 1e-12);
    }

    #[DataProvider('formats')]
    public function test_verliezersgemiddelde_is_som_verliezende_setscores_over_drie(SeasonFormat $format): void
    {
        $win = $format->win();
        $lose = $format->lose();
        $close = $format->loseClose();
        $statistics = GameStatistics::fromScores($win, $lose, $win, $close, $lose, $win, $format->value());

        $this->assertEqualsWithDelta(($lose + $close + $lose) / 3, $statistics->averageLosing, 1e-12);
    }

    #[DataProvider('formats')]
    public function test_scores_boven_het_setmaximum_worden_herschaald(SeasonFormat $format): void
    {
        $cap = $format->value();
        $extWin = $format->extensionWin();
        $extLose = $format->extensionLose();
        $win = $format->win();
        // Verlenging: winnaar telt als het setmaximum, verliezer evenredig.
        $statistics = GameStatistics::fromScores($extWin, $extLose, $win, 10, $win, 10, $cap);

        $this->assertEqualsWithDelta(($cap + $cap + $cap) / 3, $statistics->averages[1], 1e-12);
        $this->assertEqualsWithDelta($cap / $extWin * $extLose, 3 * $statistics->averageLosing - 10 - 10, 1e-12);
        $this->assertSame($extWin + $win + $win, $statistics->pointsWon[1]);
    }

    #[DataProvider('formats')]
    public function test_lege_derde_set_telt_als_setwinst_voor_spelers_2_en_3(SeasonFormat $format): void
    {
        $win = $format->win();
        $lose = $format->lose();
        // Legacy-gedrag: 0-0 valt in de else-tak, dus P2 en P3 "winnen" set 3.
        $statistics = GameStatistics::fromScores($win, $lose, $win, $lose, 0, 0, $format->value());

        $this->assertSame([1 => 2, 2 => 2, 3 => 2, 4 => 0], $statistics->setsWon);
        $this->assertEqualsWithDelta(($lose + $lose + 0) / 3, $statistics->averageLosing, 1e-12);
    }

    #[DataProvider('formats')]
    public function test_gelijke_set_telt_als_winst_voor_away_team(SeasonFormat $format): void
    {
        $win = $format->win();
        $lose = $format->lose();
        $statistics = GameStatistics::fromScores($win, $win, $win, $lose, $lose, $win, $format->value());

        // Set 1 gelijk → P3,P4 winnen. Set 2: P1,P3. Set 3: P2,P3.
        $this->assertSame([1 => 1, 2 => 1, 3 => 3, 4 => 1], $statistics->setsWon);
    }

    public function test_dezelfde_verlenging_weegt_anders_op_15_dan_op_21(): void
    {
        // 24-22: op 15 herschaald naar 15, op 21 naar 21.
        $tot15 = GameStatistics::fromScores(24, 22, 15, 10, 15, 10, PointsPerSet::Fifteen->value);
        $tot21 = GameStatistics::fromScores(24, 22, 21, 10, 21, 10, PointsPerSet::TwentyOne->value);

        $this->assertEqualsWithDelta(15.0, $tot15->averages[1], 1e-12);
        $this->assertEqualsWithDelta(21.0, $tot21->averages[1], 1e-12);
        $this->assertEqualsWithDelta(15 / 24 * 22, 3 * $tot15->averageLosing - 10 - 10, 1e-12);
        $this->assertEqualsWithDelta(21 / 24 * 22, 3 * $tot21->averageLosing - 10 - 10, 1e-12);
    }
}
