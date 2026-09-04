<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Player;
use App\Models\PlayerSeasonStatistic;
use App\Models\Round;
use App\Models\Season;
use App\Services\SeasonCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PlaysToPoints;
use Tests\TestCase;

class AutomaticRecalculationTest extends TestCase
{
    use PlaysToPoints;
    use RefreshDatabase;

    private Season $season;

    private Round $round;

    /** @var array<int, Player> */
    private array $players = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootFormat();

        $this->season = Season::create([
            'name' => '2026 - 2027',
            'points_per_set' => $this->format->pointsPerSet,
        ]);
        $this->round = $this->season->rounds()->create([
            'number' => 1,
            'date' => '2026-09-01',
        ]);

        foreach (range(1, 8) as $index) {
            $player = Player::create([
                'first_name' => "Speler{$index}",
                'last_name' => 'Test',
                'gender' => 'male',
                'birth_date' => '1990-01-01',
                'double_ranking' => 100,
                'plays_competition' => true,
                'is_member' => true,
            ]);
            $this->players[$index] = $player;

            PlayerSeasonStatistic::create([
                'season_id' => $this->season->id,
                'player_id' => $player->id,
                'base_points' => $this->format->startingBasePoints(),
            ]);
        }
    }

    public function test_geen_herberekening_zolang_een_game_onvolledig_is(): void
    {
        $this->createGame([1, 2, 3, 4], complete: true);
        $this->createGame([5, 6, 7, 8], complete: false);

        $this->assertFalse($this->round->fresh()->is_calculated);
        $this->assertNull($this->round->fresh()->average_absent);
    }

    public function test_herberekening_zodra_de_laatste_score_is_ingevuld(): void
    {
        $this->createGame([1, 2, 3, 4], complete: true);
        $incompleteGame = $this->createGame([5, 6, 7, 8], complete: false);

        $incompleteGame->update($this->format->straightSets());

        $round = $this->round->fresh();
        $this->assertTrue($round->is_calculated);
        $this->assertNotNull($round->average_absent);

        // Elke deelnemende speler heeft nu een berekend gemiddelde voor deze speeldag.
        $this->assertSame(8, $round->playerStatistics()->whereNotNull('average')->count());
    }

    public function test_verwijderen_van_de_onvolledige_game_maakt_de_speeldag_berekenbaar(): void
    {
        $this->createGame([1, 2, 3, 4], complete: true);
        $incompleteGame = $this->createGame([5, 6, 7, 8], complete: false);

        $this->assertFalse($this->round->fresh()->is_calculated);

        $incompleteGame->delete();

        $this->assertTrue($this->round->fresh()->is_calculated);
    }

    public function test_een_onvolledige_game_toevoegen_haalt_de_speeldag_weer_uit_de_stand(): void
    {
        $this->createGame([1, 2, 3, 4], complete: true);
        $this->assertTrue($this->round->fresh()->is_calculated);

        $this->createGame([5, 6, 7, 8], complete: false);

        $round = $this->round->fresh();
        $this->assertFalse($round->is_calculated);
        $this->assertNull($round->average_absent);
        $this->assertSame(0, $round->playerStatistics()->whereNotNull('average')->count());
    }

    public function test_een_set_invullen_op_een_onvolledige_speeldag_herberekent_niet(): void
    {
        $this->createGame([1, 2, 3, 4], complete: true);
        $game = $this->createGame([5, 6, 7, 8], complete: false);

        $this->mock(SeasonCalculator::class)->shouldReceive('calculate')->never();

        // Set 2 erbij: de match blijft onvolledig, dus de speeldag telt nog altijd
        // niet mee en de stand kan onmogelijk veranderd zijn.
        $game->update([
            'set2_home' => $this->format->win(),
            'set2_away' => $this->format->lose(),
        ]);
    }

    public function test_een_avond_kost_een_enkele_berekening(): void
    {
        $calculator = $this->partialMock(
            SeasonCalculator::class,
            fn ($mock) => $mock->shouldReceive('calculate')->passthru(),
        );

        $first = $this->createGame([1, 2, 3, 4], complete: false);
        $second = $this->createGame([5, 6, 7, 8], complete: false);

        foreach ([$first, $second] as $game) {
            foreach ([2, 3] as $set) {
                $game->update([
                    "set{$set}_home" => $this->format->win(),
                    "set{$set}_away" => $this->format->lose(),
                ]);
            }
        }

        // Twee matches aanmaken en vier keer een setstand bewaren: pas de laatste
        // maakt de speeldag volledig, en enkel die hoort te rekenen.
        $calculator->shouldHaveReceived('calculate')->once();
        $this->assertTrue($this->round->fresh()->is_calculated);
    }

    /** @param array<int, int> $playerIndexes */
    private function createGame(array $playerIndexes, bool $complete): Game
    {
        $scores = $complete
            ? $this->format->straightSets()
            : $this->format->firstSetOnly();

        return $this->round->games()->create([
            'player1_id' => $this->players[$playerIndexes[0]]->id,
            'player2_id' => $this->players[$playerIndexes[1]]->id,
            'player3_id' => $this->players[$playerIndexes[2]]->id,
            'player4_id' => $this->players[$playerIndexes[3]]->id,
            ...$scores,
        ]);
    }
}
