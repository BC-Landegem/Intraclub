<?php

namespace Tests\Feature;

use App\Enums\PointsPerSet;
use App\Models\Player;
use App\Models\PlayerSeasonStatistic;
use App\Models\Season;
use App\Services\SeasonCreator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\SeasonFormat;
use Tests\TestCase;

class SeasonCreatorTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{0: SeasonFormat}> */
    public static function formats(): array
    {
        return SeasonFormat::provider();
    }

    #[DataProvider('formats')]
    public function test_nieuw_seizoen_krijgt_basispunten_volgens_de_puntenschaal(SeasonFormat $format): void
    {
        $vorig = Season::create([
            'name' => '2025 - 2026',
            'points_per_set' => PointsPerSet::TwentyOne,
        ]);
        $speler = Player::create([
            'first_name' => 'Jan',
            'last_name' => 'Test',
            'gender' => 'male',
            'birth_date' => '1990-01-01',
            'double_ranking' => 100,
            'plays_competition' => true,
            'is_member' => true,
        ]);
        PlayerSeasonStatistic::create([
            'season_id' => $vorig->id,
            'player_id' => $speler->id,
            'base_points' => 19.0,
        ]);

        $seizoen = app(SeasonCreator::class)->create('2026 - 2027', $format->pointsPerSet);

        $this->assertSame($format->value(), $seizoen->points_per_set->value);
        $this->assertEqualsWithDelta(
            $format->startingBasePoints(),
            (float) $seizoen->playerStatistics()->where('player_id', $speler->id)->value('base_points'),
            1e-9,
        );
    }
}
