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
        DB::table('seasons')->insert(['id' => 1, 'name' => '2025 - 2026']);
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
                    'set1_home' => 21, 'set1_away' => 15, 'set2_home' => 21, 'set2_away' => 16,
                    'set3_home' => 21, 'set3_away' => 17,
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
