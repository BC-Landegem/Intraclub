<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Player;
use App\Models\PlayerRoundStatistic;
use App\Models\PlayerSeasonStatistic;
use App\Models\Round;
use App\Models\Season;
use App\Services\DrawService;
use App\Services\SeasonCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Loting en de gevolgen van uitgeloot-zijn.
 */
class DrawAndDrawnOutTest extends TestCase
{
    use RefreshDatabase;

    private Season $season;

    /** @var array<int, Player> */
    private array $players = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->season = Season::create(['name' => '2026 - 2027']);

        foreach (range(1, 12) as $index) {
            $player = Player::create([
                'first_name' => sprintf('Speler%02d', $index),
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
                'base_points' => 19 + $index / 100,
            ]);
        }
    }

    public function test_loting_bewaart_wie_uitgeloot_is(): void
    {
        // 6 aanwezigen: één game van 4, twee spelers blijven over.
        $round = $this->roundWithPresentPlayers(1, range(1, 6));

        $result = app(DrawService::class)->draw($round);

        $this->assertCount(1, $result['games']);
        $this->assertCount(2, $result['drawnOut']);

        $storedDrawnOut = $round->playerStatistics()->where('is_drawn_out', true)->pluck('player_id')->all();
        $this->assertEqualsCanonicalizing($result['drawnOut'], $storedDrawnOut);
    }

    public function test_alle_aanwezigen_worden_ingedeeld_als_het_aantal_deelbaar_is(): void
    {
        $round = $this->roundWithPresentPlayers(1, range(1, 12));

        $result = app(DrawService::class)->draw($round);

        $this->assertCount(3, $result['games']);
        $this->assertSame([], $result['drawnOut']);
        $this->assertCount(12, array_unique(array_merge(...$result['games'])));
    }

    public function test_opnieuw_loten_wist_de_vorige_uitgelote_spelers(): void
    {
        $round = $this->roundWithPresentPlayers(1, range(1, 6));
        $service = app(DrawService::class);

        $first = $service->draw($round);
        $second = $service->draw($round);

        $stored = $round->playerStatistics()->where('is_drawn_out', true)->pluck('player_id')->all();
        $this->assertCount(2, $stored, 'Er mogen nooit meer uitgelote spelers bewaard staan dan de laatste loting opleverde.');
        $this->assertEqualsCanonicalizing($second['drawnOut'], $stored);
        $this->assertNotEmpty($first['drawnOut']);
    }

    public function test_wie_uitgeloot_was_is_de_volgende_speeldagen_beschermd(): void
    {
        // Speeldag 1: speler 11 en 12 zijn uitgeloot. Met tien aanwezigen per
        // speeldag blijven er genoeg onbeschermde spelers over om te rouleren.
        $this->markDrawnOut($this->roundWithPresentPlayers(1, range(1, 12)), [11, 12]);

        foreach (range(2, 1 + DrawService::PROTECTED_ROUNDS) as $number) {
            $result = app(DrawService::class)->draw($this->roundWithPresentPlayers($number, range(1, 10)));

            $this->assertNotContains($this->players[11]->id, $result['drawnOut'], "Speeldag {$number}: speler 11 is nog beschermd.");
            $this->assertNotContains($this->players[12]->id, $result['drawnOut'], "Speeldag {$number}: speler 12 is nog beschermd.");
        }
    }

    public function test_bescherming_vervalt_na_het_venster(): void
    {
        $this->markDrawnOut($this->roundWithPresentPlayers(1, range(1, 6)), [5, 6]);

        // Speeldag 6 valt buiten het venster van 4 speeldagen: iedereen kan weer
        // aan de kant komen, dus de bescherming mag niet meer meespelen.
        $round = $this->roundWithPresentPlayers(2 + DrawService::PROTECTED_ROUNDS, range(1, 6));
        $result = app(DrawService::class)->draw($round);

        $this->assertCount(2, $result['drawnOut']);
        $this->assertCount(1, $result['games']);
    }

    public function test_bij_te_weinig_onbeschermde_spelers_blijft_wie_het_langst_geleden_zat(): void
    {
        // Iedereen die op speeldag 3 aanwezig is, is beschermd: speler 5 sinds
        // speeldag 1, de rest sinds speeldag 2. Er moet er tóch één aan de kant, en
        // dat wordt speler 5 — die zat het langst geleden en is dus het eerst weer
        // aan de beurt.
        $this->markDrawnOut($this->roundWithPresentPlayers(1, range(1, 6)), [5]);
        $this->markDrawnOut($this->roundWithPresentPlayers(2, range(1, 6)), [1, 2, 3, 4]);

        $result = app(DrawService::class)->draw($this->roundWithPresentPlayers(3, range(1, 5)));

        $this->assertSame([$this->players[5]->id], $result['drawnOut']);
    }

    public function test_uitgelote_speeldag_telt_niet_mee_in_het_gemiddelde(): void
    {
        $round = $this->roundWithPresentPlayers(1, range(1, 6));
        $this->completeGame($round, [1, 2, 3, 4]);

        // Speler 5 en 6 uitgeloot: geen game, dus deze speeldag telt niet voor hen.
        $round->playerStatistics()
            ->whereIn('player_id', [$this->players[5]->id, $this->players[6]->id])
            ->update(['is_drawn_out' => true]);

        app(SeasonCalculator::class)->calculate($this->season);

        $drawnOutAverage = $this->averageFor($round, 5);
        $basePoints = (float) PlayerSeasonStatistic::where('player_id', $this->players[5]->id)->value('base_points');

        // Alleen de basispunten resteren: het verliezersgemiddelde is niet toegekend.
        $this->assertEqualsWithDelta($basePoints, $drawnOutAverage, 1e-9);
    }

    public function test_afwezige_speler_krijgt_wel_het_verliezersgemiddelde(): void
    {
        $round = $this->roundWithPresentPlayers(1, range(1, 4));
        $this->completeGame($round, [1, 2, 3, 4]);

        // Speler 7 was er niet en heeft dus geen aanwezigheidsrij.
        app(SeasonCalculator::class)->calculate($this->season);

        $absentAverage = $this->averageFor($round, 7);
        $basePoints = (float) PlayerSeasonStatistic::where('player_id', $this->players[7]->id)->value('base_points');
        $averageAbsent = (float) $round->fresh()->average_absent;

        $this->assertEqualsWithDelta(($basePoints + $averageAbsent) / 2, $absentAverage, 1e-9);
    }

    public function test_laatkomer_vult_de_match_aan_en_de_uitgelote_spelers_spelen_toch_mee(): void
    {
        $round = $this->roundWithPresentPlayers(1, range(1, 6));
        $this->completeGame($round, [1, 2, 3, 4]);

        $round->playerStatistics()
            ->whereIn('player_id', [$this->players[5]->id, $this->players[6]->id])
            ->update(['is_drawn_out' => true]);

        // Twee laatkomers melden zich; de onvolledige match kan doorgaan.
        foreach ([7, 8] as $index) {
            PlayerRoundStatistic::updateOrCreate(
                ['round_id' => $round->id, 'player_id' => $this->players[$index]->id],
                ['is_present' => true],
            );
        }
        $this->completeGame($round, [5, 6, 7, 8]);

        // De vlag is gewist en die speeldag telt weer mee voor 5 en 6.
        $this->assertSame(0, $round->playerStatistics()->where('is_drawn_out', true)->count());

        $statistic = PlayerSeasonStatistic::where('player_id', $this->players[5]->id)->first();
        $this->assertSame(1, $statistic->games_played);
        $this->assertSame(1, $statistic->rounds_present);
    }

    public function test_opnieuw_loten_deelt_enkel_spelers_zonder_match_in(): void
    {
        // Acht aanwezigen, waarvan er vier al een match hebben.
        $round = $this->roundWithPresentPlayers(1, range(1, 8));
        $this->completeGame($round, [1, 2, 3, 4]);

        $result = app(DrawService::class)->draw($round);

        $this->assertCount(1, $result['games'], 'Enkel de vier resterende spelers vormen een nieuwe match.');
        $this->assertSame([], $result['drawnOut']);

        $ingedeeld = $result['games'][0];
        foreach ([1, 2, 3, 4] as $index) {
            $this->assertNotContains($this->players[$index]->id, $ingedeeld, 'Wie al speelt, mag niet opnieuw ingedeeld worden.');
        }
    }

    public function test_laatkomers_maken_dat_uitgelote_spelers_alsnog_kunnen_spelen(): void
    {
        // Zes aanwezigen: één match, twee uitgeloot.
        $round = $this->roundWithPresentPlayers(1, range(1, 6));
        $eerste = app(DrawService::class)->draw($round);
        $this->completeGame($round, array_map(
            fn (int $id): int => array_search($id, array_map(fn ($p) => $p->id, $this->players), true),
            $eerste['games'][0],
        ));

        // Twee laatkomers erbij en opnieuw loten.
        foreach ([7, 8] as $index) {
            PlayerRoundStatistic::updateOrCreate(
                ['round_id' => $round->id, 'player_id' => $this->players[$index]->id],
                ['is_present' => true],
            );
        }

        $tweede = app(DrawService::class)->draw($round);

        $this->assertCount(1, $tweede['games']);
        $this->assertSame([], $tweede['drawnOut'], 'Niemand blijft nog aan de kant.');
        foreach ($eerste['drawnOut'] as $playerId) {
            $this->assertContains($playerId, $tweede['games'][0], 'De eerder uitgelote spelers spelen nu mee.');
        }
    }

    /** @param list<int> $playerIndexes */
    private function markDrawnOut(Round $round, array $playerIndexes): void
    {
        $round->playerStatistics()
            ->whereIn('player_id', array_map(fn (int $index): int => $this->players[$index]->id, $playerIndexes))
            ->update(['is_drawn_out' => true]);
    }

    /** @param list<int> $playerIndexes */
    private function roundWithPresentPlayers(int $number, array $playerIndexes): Round
    {
        $round = $this->season->rounds()->create([
            'number' => $number,
            'date' => sprintf('2026-09-%02d', $number),
        ]);

        foreach ($playerIndexes as $index) {
            PlayerRoundStatistic::updateOrCreate(
                ['round_id' => $round->id, 'player_id' => $this->players[$index]->id],
                ['is_present' => true],
            );
        }

        return $round;
    }

    private function completeGame(Round $round, array $playerIndexes): Game
    {
        return $round->games()->create([
            'player1_id' => $this->players[$playerIndexes[0]]->id,
            'player2_id' => $this->players[$playerIndexes[1]]->id,
            'player3_id' => $this->players[$playerIndexes[2]]->id,
            'player4_id' => $this->players[$playerIndexes[3]]->id,
            'set1_home' => 21, 'set1_away' => 15,
            'set2_home' => 21, 'set2_away' => 15,
            'set3_home' => 21, 'set3_away' => 15,
        ]);
    }

    private function averageFor(Round $round, int $playerIndex): float
    {
        return (float) $round->playerStatistics()
            ->where('player_id', $this->players[$playerIndex]->id)
            ->value('average');
    }
}
