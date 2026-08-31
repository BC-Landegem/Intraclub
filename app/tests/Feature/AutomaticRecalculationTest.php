<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Player;
use App\Models\PlayerSeasonStatistic;
use App\Models\Round;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AutomaticRecalculationTest extends TestCase
{
    use RefreshDatabase;

    private Season $season;

    private Round $round;

    /** @var array<int, Player> */
    private array $players = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->season = Season::create(['name' => '2026 - 2027']);
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
                'base_points' => 19,
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

        $incompleteGame->update([
            'set1_home' => 21, 'set1_away' => 15,
            'set2_home' => 21, 'set2_away' => 15,
            'set3_home' => 21, 'set3_away' => 15,
        ]);

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

    /** @param array<int, int> $playerIndexes */
    private function createGame(array $playerIndexes, bool $complete): Game
    {
        $scores = $complete
            ? ['set1_home' => 21, 'set1_away' => 15, 'set2_home' => 21, 'set2_away' => 15, 'set3_home' => 21, 'set3_away' => 15]
            : ['set1_home' => 21, 'set1_away' => 15, 'set2_home' => null, 'set2_away' => null, 'set3_home' => null, 'set3_away' => null];

        return $this->round->games()->create([
            'player1_id' => $this->players[$playerIndexes[0]]->id,
            'player2_id' => $this->players[$playerIndexes[1]]->id,
            'player3_id' => $this->players[$playerIndexes[2]]->id,
            'player4_id' => $this->players[$playerIndexes[3]]->id,
            ...$scores,
        ]);
    }
}
