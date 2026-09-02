<?php

namespace Tests\Feature;

use App\Enums\PointsPerSet;
use App\Exceptions\SeasonScaleIsFixed;
use App\Models\Player;
use App\Models\PlayerSeasonStatistic;
use App\Models\Round;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\SeasonFormat;
use Tests\TestCase;

/**
 * De puntenschaal van een bestaand seizoen.
 *
 * Zolang het seizoen leeg is mag ze om: dan verschuiven enkel de basispunten mee.
 * Zodra er een speeldag staat ligt ze vast — omzetten zou de setstanden die er al
 * zijn herinterpreteren op een schaal waarop ze nooit gespeeld zijn.
 *
 * En elke ándere wijziging aan een seizoen, een naam corrigeren bijvoorbeeld, moet
 * de bevroren stand met rust laten.
 */
class SeasonScaleChangeTest extends TestCase
{
    use RefreshDatabase;

    private SeasonFormat $vanaf;

    private SeasonFormat $naar;

    private Season $season;

    /** @var array<int, Player> */
    private array $players = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->vanaf = new SeasonFormat(PointsPerSet::Fifteen);
        $this->naar = new SeasonFormat(PointsPerSet::TwentyOne);

        $this->season = Season::create([
            'name' => '2026 - 2027',
            'points_per_set' => $this->vanaf->pointsPerSet,
        ]);

        foreach (range(1, 4) as $index) {
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
                // Iedereen een eigen startpunt, zodat de test ziet of de onderlinge
                // afstanden na het omzetten intact blijven.
                'base_points' => $this->vanaf->startingBasePoints() + $index * 0.0001,
            ]);
        }
    }

    public function test_omzetten_verschuift_de_basispunten_mee_zolang_het_seizoen_leeg_is(): void
    {
        $this->season->update(['points_per_set' => $this->naar->pointsPerSet]);

        foreach ($this->players as $index => $player) {
            $this->assertEqualsWithDelta(
                $this->naar->startingBasePoints() + $index * 0.0001,
                $this->basePointsOf($player),
                1e-9,
                "basispunten van speler {$index}",
            );
        }
    }

    public function test_omzetten_faalt_zodra_er_een_speeldag_staat(): void
    {
        $this->addRound();

        $this->expectException(SeasonScaleIsFixed::class);

        try {
            $this->season->update(['points_per_set' => $this->naar->pointsPerSet]);
        } finally {
            // Niets half doorgevoerd: noch de schaal, noch de basispunten.
            $this->assertSame(
                $this->vanaf->value(),
                (int) DB::table('seasons')->where('id', $this->season->id)->value('points_per_set'),
            );
            $this->assertEqualsWithDelta(
                $this->vanaf->startingBasePoints() + 0.0001,
                $this->basePointsOf($this->players[1]),
                1e-9,
            );
        }
    }

    public function test_een_lege_speeldag_zet_de_schaal_al_vast(): void
    {
        // De schaal hoort bij de speeldag zelf, niet pas bij de eerste ingevulde score:
        // wie in de zaal staat weet dan al tot hoeveel er gespeeld wordt.
        $this->addRound();

        $this->assertSame(0, Round::query()->where('season_id', $this->season->id)->first()->games()->count());

        $this->expectException(SeasonScaleIsFixed::class);

        $this->season->update(['points_per_set' => $this->naar->pointsPerSet]);
    }

    public function test_een_naamswijziging_laat_de_bevroren_stand_ongemoeid(): void
    {
        $round = $this->addRound();
        $this->playGame($round);

        $voor = $this->standingOf($round);

        // Wie geen lid meer is verdwijnt uit een herberekening: precies zo raakt een
        // afgesloten seizoen zijn historiek kwijt als elke bewerking zou herrekenen.
        $this->players[1]->update(['is_member' => false]);

        $this->season->update(['name' => '2026 - 2027 (gecorrigeerd)']);

        $this->assertEquals($voor, $this->standingOf($round));
    }

    public function test_een_wijziging_zonder_nieuwe_schaal_verschuift_de_basispunten_niet(): void
    {
        $voor = $this->allBasePoints();

        $this->season->update(['name' => 'Nog een andere naam']);

        $this->assertEquals($voor, $this->allBasePoints());
    }

    private function addRound(): Round
    {
        return $this->season->rounds()->create(['number' => 1, 'date' => '2026-09-01']);
    }

    private function playGame(Round $round): void
    {
        $round->games()->create([
            'player1_id' => $this->players[1]->id,
            'player2_id' => $this->players[2]->id,
            'player3_id' => $this->players[3]->id,
            'player4_id' => $this->players[4]->id,
        ] + $this->vanaf->straightSets());
    }

    private function basePointsOf(Player $player): float
    {
        return (float) PlayerSeasonStatistic::query()
            ->where('season_id', $this->season->id)
            ->where('player_id', $player->id)
            ->value('base_points');
    }

    /** @return list<float> */
    private function allBasePoints(): array
    {
        return PlayerSeasonStatistic::query()
            ->where('season_id', $this->season->id)
            ->orderBy('player_id')
            ->pluck('base_points')
            ->all();
    }

    /** @return list<array{player_id: int, average: float|null, rank: int|null}> */
    private function standingOf(Round $round): array
    {
        return DB::table('player_round_statistics')
            ->where('round_id', $round->id)
            ->orderBy('player_id')
            ->get(['player_id', 'average', 'rank'])
            ->map(fn (object $rij): array => (array) $rij)
            ->all();
    }
}
