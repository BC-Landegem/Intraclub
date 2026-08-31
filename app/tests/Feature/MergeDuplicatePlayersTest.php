<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Samenvoegen van spelers die tweemaal in `players` beland zijn. De paren komen uit
 * database/legacy/player-map-overrides.php; deze test gebruikt de id's die daar staan.
 */
class MergeDuplicatePlayersTest extends TestCase
{
    use RefreshDatabase;

    private const BLIJFT = 36;

    private const DUBBEL = 132;

    public function test_het_zet_wedstrijden_over_en_verwijdert_de_dubbel(): void
    {
        $this->gegevenEenDubbel(gamesBlijft: 2, gamesDubbel: 1);

        $this->artisan('intraclub:merge-duplicates', ['--force' => true])->assertSuccessful();

        $this->assertNull(DB::table('players')->find(self::DUBBEL));
        $this->assertSame(3, $this->aantalGames(self::BLIJFT));
    }

    public function test_aanwezigheid_van_beide_fiches_blijft_behouden(): void
    {
        $this->gegevenEenDubbel(gamesBlijft: 1, gamesDubbel: 1);

        // Speeldag 1 heeft van beide fiches een rij; enkel de dubbel stond aanwezig.
        DB::table('player_round_statistics')->where('player_id', self::BLIJFT)->where('round_id', 1)
            ->update(['is_present' => false]);
        DB::table('player_round_statistics')->where('player_id', self::DUBBEL)->where('round_id', 1)
            ->update(['is_present' => true]);

        $this->artisan('intraclub:merge-duplicates', ['--force' => true])->assertSuccessful();

        $rij = DB::table('player_round_statistics')
            ->where('player_id', self::BLIJFT)->where('round_id', 1)->first();

        $this->assertTrue((bool) $rij->is_present);
        // Eén rij per speeldag, niet twee.
        $this->assertSame(2, DB::table('player_round_statistics')->where('player_id', self::BLIJFT)->count());
    }

    public function test_het_weigert_de_fiche_met_de_meeste_wedstrijden_te_verwijderen(): void
    {
        $this->gegevenEenDubbel(gamesBlijft: 1, gamesDubbel: 2);

        $this->artisan('intraclub:merge-duplicates', ['--force' => true])
            ->expectsOutputToContain('Draai de richting om')
            ->assertSuccessful();

        $this->assertNotNull(DB::table('players')->find(self::DUBBEL));
    }

    /**
     * De herberekening slaat niet-leden over, dus voor hen is de opgeslagen seizoensrij het
     * enige wat er nog is. Speelde de dubbel dat seizoen en de blijvende fiche niet, dan moeten
     * zijn tellers én zijn basispunten mee — anders valt het seizoen op nul.
     */
    public function test_seizoenstellers_van_een_niet_lid_gaan_mee(): void
    {
        $this->gegevenEenDubbel(gamesBlijft: 0, gamesDubbel: 1);
        // Zoals in de echte data: de blijvende fiche speelde vorige seizoenen, de dubbel dit
        // seizoen. Zonder die voorgeschiedenis zou het commando de richting weigeren.
        $this->gegevenEenVorigSeizoenVoorDeBlijver();

        DB::table('players')->whereIn('id', [self::BLIJFT, self::DUBBEL])->update(['is_member' => false]);
        DB::table('player_season_statistics')->where('season_id', 1)->where('player_id', self::BLIJFT)
            ->update(['base_points' => 19.0033]);
        DB::table('player_season_statistics')->where('season_id', 1)->where('player_id', self::DUBBEL)->update([
            'base_points' => 19.0, 'sets_played' => 3, 'sets_won' => 3,
            'points_played' => 79, 'points_won' => 45, 'rounds_present' => 1, 'games_played' => 1,
        ]);

        $this->artisan('intraclub:merge-duplicates', ['--force' => true])->assertSuccessful();

        $rijen = DB::table('player_season_statistics')->where('season_id', 1)->get();
        $this->assertCount(1, $rijen->where('player_id', self::BLIJFT));
        $this->assertCount(0, $rijen->where('player_id', self::DUBBEL));

        $rij = $rijen->firstWhere('player_id', self::BLIJFT);
        $this->assertSame(1, $rij->games_played);
        $this->assertSame(3, $rij->sets_played);
        $this->assertSame(79, $rij->points_played);
        $this->assertSame(1, $rij->rounds_present);
        $this->assertEqualsWithDelta(19.0, $rij->base_points, 0.00001);
    }

    /**
     * Bij een lid herberekent alles zich toch, en dan horen de basispunten van de blijvende
     * fiche te blijven staan: die volgen uit zijn eindstand van vorig seizoen, terwijl de
     * dubbel als nieuwkomer op 19,0000 begon.
     */
    public function test_een_lid_houdt_de_basispunten_van_de_blijvende_fiche(): void
    {
        $this->gegevenEenDubbel(gamesBlijft: 0, gamesDubbel: 1);
        $this->gegevenEenVorigSeizoenVoorDeBlijver();

        DB::table('player_season_statistics')->where('season_id', 1)->where('player_id', self::BLIJFT)
            ->update(['base_points' => 19.0054]);
        DB::table('player_season_statistics')->where('season_id', 1)->where('player_id', self::DUBBEL)
            ->update(['base_points' => 19.0, 'games_played' => 1]);

        $this->artisan('intraclub:merge-duplicates', ['--force' => true])->assertSuccessful();

        $rij = DB::table('player_season_statistics')
            ->where('season_id', 1)->where('player_id', self::BLIJFT)->first();

        $this->assertEqualsWithDelta(19.0054, $rij->base_points, 0.00001);
    }

    /**
     * Wie terugkeert wordt soms opnieuw aangemaakt, en dan draagt net de fiche die verdwijnt
     * het lidvinkje. Dat moet mee, en alle seizoenen van de speler moeten daarna opnieuw
     * gerekend worden: een lid neemt een plaats in het klassement in, een niet-lid niet.
     */
    public function test_het_lidvinkje_van_de_dubbel_gaat_mee(): void
    {
        $this->gegevenEenDubbel(gamesBlijft: 0, gamesDubbel: 1);
        $this->gegevenEenVorigSeizoenVoorDeBlijver();

        DB::table('players')->where('id', self::BLIJFT)->update(['is_member' => false]);
        DB::table('players')->where('id', self::DUBBEL)->update(['is_member' => true]);

        $this->artisan('intraclub:merge-duplicates', ['--force' => true])
            ->expectsOutputToContain('het vinkje stond op de dubbel')
            ->assertSuccessful();

        $this->assertTrue((bool) DB::table('players')->where('id', self::BLIJFT)->value('is_member'));

        // Ook het seizoen dat de merge zelf niet aanraakt krijgt opnieuw een klassement.
        $this->assertNotNull(
            DB::table('player_round_statistics')
                ->where('player_id', self::BLIJFT)->where('round_id', 3)->value('rank'),
        );
    }

    public function test_een_tweede_run_doet_niets(): void
    {
        $this->gegevenEenDubbel(gamesBlijft: 1, gamesDubbel: 1);

        $this->artisan('intraclub:merge-duplicates', ['--force' => true])->assertSuccessful();
        $this->artisan('intraclub:merge-duplicates', ['--force' => true])
            ->expectsOutputToContain('Niets samen te voegen')
            ->assertSuccessful();
    }

    private function gegevenEenDubbel(int $gamesBlijft, int $gamesDubbel): void
    {
        DB::table('seasons')->insert(['id' => 1, 'name' => '2025 - 2026', 'points_per_set' => 15]);
        DB::table('rounds')->insert([
            ['id' => 1, 'season_id' => 1, 'number' => 1, 'date' => '2025-09-24', 'is_calculated' => false],
            ['id' => 2, 'season_id' => 1, 'number' => 2, 'date' => '2025-10-08', 'is_calculated' => false],
        ]);

        foreach ([self::BLIJFT, self::DUBBEL, 200, 201, 202] as $id) {
            DB::table('players')->insert([
                'id' => $id, 'first_name' => 'Speler', 'last_name' => "Nr{$id}", 'gender' => 'male',
                'birth_date' => '1980-01-01', 'double_ranking' => 5, 'plays_competition' => false, 'is_member' => true,
            ]);
        }

        $gameId = 1;
        foreach ([self::BLIJFT => $gamesBlijft, self::DUBBEL => $gamesDubbel] as $spelerId => $aantal) {
            for ($i = 0; $i < $aantal; $i++) {
                DB::table('games')->insert([
                    'id' => $gameId++, 'round_id' => 1,
                    'player1_id' => $spelerId, 'player2_id' => 200, 'player3_id' => 201, 'player4_id' => 202,
                    'set1_home' => 15, 'set1_away' => 11, 'set2_home' => 15, 'set2_away' => 11,
                    'set3_home' => 15, 'set3_away' => 12,
                ]);
            }
        }

        // Speeldag 1 kennen beide fiches, speeldag 2 alleen de dubbel.
        DB::table('player_round_statistics')->insert([
            ['round_id' => 1, 'player_id' => self::BLIJFT, 'is_present' => true, 'is_drawn_out' => false],
            ['round_id' => 1, 'player_id' => self::DUBBEL, 'is_present' => true, 'is_drawn_out' => false],
            ['round_id' => 2, 'player_id' => self::DUBBEL, 'is_present' => true, 'is_drawn_out' => false],
        ]);
        DB::table('player_season_statistics')->insert([
            ['season_id' => 1, 'player_id' => self::BLIJFT, 'base_points' => 19.0],
            ['season_id' => 1, 'player_id' => self::DUBBEL, 'base_points' => 19.0],
        ]);
    }

    private function gegevenEenVorigSeizoenVoorDeBlijver(): void
    {
        DB::table('seasons')->insert(['id' => 2, 'name' => '2024-2025', 'points_per_set' => 15]);
        DB::table('rounds')->insert(
            ['id' => 3, 'season_id' => 2, 'number' => 1, 'date' => '2024-09-25', 'is_calculated' => false],
        );

        foreach ([100, 101] as $gameId) {
            DB::table('games')->insert([
                'id' => $gameId, 'round_id' => 3,
                'player1_id' => self::BLIJFT, 'player2_id' => 200, 'player3_id' => 201, 'player4_id' => 202,
                'set1_home' => 15, 'set1_away' => 11, 'set2_home' => 15, 'set2_away' => 11,
                'set3_home' => 15, 'set3_away' => 12,
            ]);
        }

        DB::table('player_round_statistics')->insert(
            ['round_id' => 3, 'player_id' => self::BLIJFT, 'is_present' => true, 'is_drawn_out' => false],
        );
        DB::table('player_season_statistics')->insert(
            ['season_id' => 2, 'player_id' => self::BLIJFT, 'base_points' => 19.0033],
        );
    }

    private function aantalGames(int $playerId): int
    {
        return DB::table('games')
            ->where(fn ($query) => $query
                ->orWhere('player1_id', $playerId)
                ->orWhere('player2_id', $playerId)
                ->orWhere('player3_id', $playerId)
                ->orWhere('player4_id', $playerId))
            ->count();
    }
}
