<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Player;
use App\Models\PlayerRoundStatistic;
use App\Models\PlayerSeasonStatistic;
use App\Models\Round;
use App\Models\Season;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Contract van /api/records.
 *
 * De opzet: vier spelers, twee speeldagen. Op speeldag 1 wint speler 1 alle drie
 * zijn sets, op speeldag 2 speler 2. Daardoor klimt speler 2 van de derde naar
 * de eerste plaats, wat de sprong-lijst een voorspelbaar antwoord geeft.
 *
 *   basispunten   p1 19,1   p2 19,2   p3 19,3   p4 19,4
 *   dagscores     winnaar 15,00, de andere drie 12,33
 *   na speeldag 1  1. p1 17,05   2. p3 15,82   3. p2 15,77   4. p4 15,20
 *   na speeldag 2  1. p2 15,51   2. p1 15,48   3. p3 14,66   4. p4 13,80
 */
class RecordsApiTest extends TestCase
{
    use RefreshDatabase;

    private Season $season;

    /** @var array<int, Player> */
    private array $players = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->season = Season::create(['name' => '2026 - 2027']);

        foreach (range(1, 4) as $index) {
            $this->players[$index] = Player::create([
                'first_name' => "Speler{$index}",
                'last_name' => 'Test',
                'gender' => 'male',
                'birth_date' => '1995-01-01',
                'double_ranking' => 100,
                'plays_competition' => true,
                'is_member' => true,
            ]);

            PlayerSeasonStatistic::create([
                'season_id' => $this->season->id,
                'player_id' => $this->players[$index]->id,
                'base_points' => 19 + $index / 10,
            ]);
        }

        // Speeldag 1: speler 1 op positie 1, dus hij wint alle drie zijn sets.
        $this->speeldag(1, '2026-09-02', [1, 2, 3, 4]);
        // Speeldag 2: speler 2 op positie 1.
        $this->speeldag(2, '2026-09-16', [2, 1, 3, 4]);
    }

    public function test_beste_avonden_breken_gelijke_dagscores_op_puntensaldo(): void
    {
        $rijen = $this->getJson('/api/records')->assertOk()->json('data.best_days');

        $this->assertSame(15.0, (float) $rijen[0]['day_score']);
        $this->assertSame(45, $rijen[0]['points_won']);
        $this->assertSame(33, $rijen[0]['points_conceded']);
        // Beide winnaars halen 15,00 met hetzelfde saldo; de recentste avond eerst.
        $this->assertSame($this->players[2]->id, $rijen[0]['player']['id']);
        $this->assertSame(2, $rijen[0]['round']['number']);
        $this->assertSame($this->players[1]->id, $rijen[1]['player']['id']);
    }

    public function test_beste_seizoenen_staan_op_eindgemiddelde(): void
    {
        $rijen = $this->getJson('/api/records')->assertOk()->json('data.best_seasons');

        $this->assertSame($this->players[2]->id, $rijen[0]['player']['id']);
        $this->assertSame(15.51, $rijen[0]['average']);
        $this->assertSame(1, $rijen[0]['rank']);
        $this->assertSame(4, $rijen[0]['players_ranked']);
        $this->assertSame('2026 - 2027', $rijen[0]['season']['name']);
    }

    public function test_grootste_sprong_met_de_omvang_van_de_stand_erbij(): void
    {
        foreach ($this->players as $player) {
            $this->aanwezig($player->id, [1, 2]);
        }

        $rijen = $this->getJson('/api/records')->assertOk()->json('data.biggest_climbs');

        $this->assertSame($this->players[2]->id, $rijen[0]['player']['id']);
        $this->assertSame(3, $rijen[0]['places']);
        $this->assertSame(4, $rijen[0]['from_rank']);
        $this->assertSame(1, $rijen[0]['to_rank']);
        $this->assertSame(1, $rijen[0]['from_round']);
        $this->assertSame(2, $rijen[0]['to_round']);
        // De gemiddeldes horen erbij: twee plaatsen winnen kan met een klein verschil,
        // en zonder deze twee getallen leest een sprong groter dan hij is.
        $this->assertSame(15.77, $rijen[0]['from_average']);
        $this->assertSame(15.51, $rijen[0]['to_average']);
        // Zonder dit getal is een sprong van 2 niet te interpreteren.
        $this->assertSame(4, $rijen[0]['players_ranked']);

        // Alleen stijgers: wie zakt hoort niet in deze lijst.
        foreach ($rijen as $rij) {
            $this->assertGreaterThan(0, $rij['places']);
        }
    }

    /**
     * De belangrijkste eigenschap van deze lijst. Wie afwezig is krijgt het
     * verliezersgemiddelde en zakt naar de staart; de eerste avond dat hij wél komt
     * levert dan een sprong van tientallen plaatsen op. Zonder de eis dat hij op
     * beide speeldagen aanwezig was, bestond de hele lijst uit zulke sprongen.
     */
    public function test_een_sprong_na_een_afwezigheid_telt_niet(): void
    {
        // Speler 2 klimt van 4 naar 1, maar was op speeldag 1 niet aanwezig.
        $this->aanwezig($this->players[2]->id, [2]);
        foreach ([1, 3, 4] as $nummer) {
            $this->aanwezig($this->players[$nummer]->id, [1, 2]);
        }

        $rijen = collect($this->getJson('/api/records')->json('data.biggest_climbs'));

        $this->assertNull($rijen->firstWhere('player.id', $this->players[2]->id));
        // De anderen zakten of bleven staan, dus er is geen enkele sprong over.
        $this->assertCount(0, $rijen);
    }

    public function test_langste_reeks_aanwezig(): void
    {
        $this->aanwezig($this->players[1]->id, [1, 2]);
        $this->aanwezig($this->players[2]->id, [2]);

        $rijen = $this->getJson('/api/records')->assertOk()->json('data.longest_streaks');

        $this->assertSame($this->players[1]->id, $rijen[0]['player']['id']);
        $this->assertSame(2, $rijen[0]['length']);
        $this->assertSame(1, $rijen[0]['from_round']);
        $this->assertSame(2, $rijen[0]['to_round']);
        $this->assertSame(2, $rijen[0]['rounds_in_season']);

        $tweede = collect($rijen)->firstWhere('player.id', $this->players[2]->id);
        $this->assertSame(1, $tweede['length']);
    }

    public function test_een_onderbroken_reeks_begint_opnieuw(): void
    {
        $this->speeldag(3, '2026-09-30', [1, 2, 3, 4]);
        // Aanwezig op 1 en 3, niet op 2: de langste reeks is dan 1, niet 2.
        $this->aanwezig($this->players[3]->id, [1, 3]);

        $rijen = collect($this->getJson('/api/records')->json('data.longest_streaks'));

        $this->assertSame(1, $rijen->firstWhere('player.id', $this->players[3]->id)['length']);
    }

    public function test_meest_gespeelde_duo(): void
    {
        $rijen = $this->getJson('/api/records')->assertOk()->json('data.most_played_duos');

        // Vier spelers geven zes koppels, en elk koppel speelt precies één set per
        // avond samen. Twee avonden, dus zes duo's met elk twee wedstrijden.
        $this->assertCount(6, $rijen);

        foreach ($rijen as $rij) {
            $this->assertSame(2, $rij['games']);
            $this->assertCount(2, $rij['players']);
            $this->assertLessThanOrEqual($rij['games'], $rij['sets_won']);
        }
    }

    public function test_meta_noemt_de_seizoenen_en_de_limiet(): void
    {
        $this->getJson('/api/records')
            ->assertOk()
            ->assertJsonPath('meta.limit', 10)
            ->assertJsonPath('meta.seasons.0.name', '2026 - 2027')
            ->assertJsonCount(1, 'meta.seasons');

        $this->getJson('/api/records?limit=2')
            ->assertOk()
            ->assertJsonPath('meta.limit', 2)
            ->assertJsonCount(2, 'data.best_days');
    }

    /**
     * Anders dan de rest van de API: zonder ?season= alle seizoenen. Een tweede
     * seizoen erbij mag de standaardlijst dus niet beperken tot het lopende.
     */
    public function test_zonder_season_gelden_alle_seizoenen(): void
    {
        Season::create(['name' => '2027 - 2028']);

        $this->getJson('/api/records')->assertOk()->assertJsonCount(2, 'meta.seasons');
        $this->getJson('/api/records?season=all')->assertOk()->assertJsonCount(2, 'meta.seasons');

        $this->getJson("/api/records?season={$this->season->id}")
            ->assertOk()
            ->assertJsonCount(1, 'meta.seasons')
            ->assertJsonPath('meta.seasons.0.id', $this->season->id);

        $this->getJson('/api/records?season=current')
            ->assertOk()
            ->assertJsonPath('meta.seasons.0.name', '2027 - 2028');

        $this->getJson('/api/records?season=999')->assertNotFound();
    }

    public function test_een_seizoen_zonder_wedstrijden_geeft_lege_lijsten(): void
    {
        $leeg = Season::create(['name' => '2027 - 2028']);

        $this->getJson("/api/records?season={$leeg->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.best_days')
            ->assertJsonCount(0, 'data.best_seasons')
            ->assertJsonCount(0, 'data.biggest_climbs')
            ->assertJsonCount(0, 'data.longest_streaks')
            ->assertJsonCount(0, 'data.most_played_duos');
    }

    /** Eén speeldag met één wedstrijd; $volgorde zijn spelersnummers op positie 1-4. */
    private function speeldag(int $nummer, string $datum, array $volgorde): Round
    {
        $round = $this->season->rounds()->create(['number' => $nummer, 'date' => $datum]);

        Game::create([
            'round_id' => $round->id,
            'player1_id' => $this->players[$volgorde[0]]->id,
            'player2_id' => $this->players[$volgorde[1]]->id,
            'player3_id' => $this->players[$volgorde[2]]->id,
            'player4_id' => $this->players[$volgorde[3]]->id,
            'set1_home' => 15, 'set1_away' => 11,
            'set2_home' => 15, 'set2_away' => 11,
            'set3_home' => 15, 'set3_away' => 11,
        ]);

        return $round;
    }

    /** @param list<int> $speeldagNummers */
    private function aanwezig(int $playerId, array $speeldagNummers): void
    {
        $roundIds = $this->season->rounds()->whereIn('number', $speeldagNummers)->pluck('id');

        PlayerRoundStatistic::where('player_id', $playerId)
            ->whereIn('round_id', $roundIds)
            ->update(['is_present' => true]);
    }
}
