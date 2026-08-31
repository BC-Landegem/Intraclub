<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Player;
use App\Models\PlayerSeasonStatistic;
use App\Models\Round;
use App\Models\Season;
use App\Services\SeasonCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * De grens van de publieke geschiedenis, route per route.
 *
 * De regel, in één zin: **één regel uit een eindstand mag altijd — die regels van
 * één persoon bij elkaar zetten mag alleen als die persoon nog lid is.** Namen
 * blijven dus staan in een uitslag; wat dichtgaat is het dossier.
 *
 * Dit bestand loopt élke publieke route af tegen een afgesloten seizoen en tegen
 * een niet-lid, en legt vast wat er terugkomt. Zonder zo'n bestand is de volgende
 * handige include het gat weer open — dat is letterlijk hoe fase 10 ontstond, waar
 * één genegeerde `?include=` een consument 800 requests kostte. De drie deuren die
 * bij het doorlopen bovenkwamen staan elk met naam in een test hieronder.
 */
class HistoryScopeTest extends TestCase
{
    use RefreshDatabase;

    private Season $closedSeason;

    private Season $currentSeason;

    private Round $closedRound;

    private Round $currentRound;

    private Player $member;

    private Player $formerMember;

    protected function setUp(): void
    {
        parent::setUp();

        [$this->closedSeason, $this->closedRound] = $this->seizoenMetSpeeldag('2025 - 2026', '2025-09-03');
        [$this->currentSeason, $this->currentRound] = $this->seizoenMetSpeeldag('2026 - 2027', '2026-09-02');

        // Speler3 staat in de eindstand van het afgesloten seizoen ónder Speler4, en
        // Speler4 is degene die stopt. Zo schuift de bevroren rank van het lid op bij
        // een herberekening terwijl de gepubliceerde stand blijft staan — anders zou
        // test_de_plaats_op_de_fiche_… ook slagen met de verkeerde bron.
        $this->member = Player::firstWhere('first_name', 'Speler3');
        $this->formerMember = Player::firstWhere('first_name', 'Speler4');

        // Speelde het afgesloten seizoen mee en is daarna gestopt. Hij hoort nog in
        // die eindstand, maar zijn fiche gaat dicht.
        $this->formerMember->update(['is_member' => false]);

        $this->seedArchief();
    }

    // ── Wat publiek blijft ────────────────────────────────────────────────────

    /**
     * De eindstand van een afgesloten seizoen, compleet, mét de namen van wie
     * ondertussen gestopt is. Een erelijst waar winnaars uit wegvallen is geen
     * erelijst, en een stand met gaten erin geen stand.
     */
    public function test_de_eindstand_van_een_afgesloten_seizoen_blijft_compleet(): void
    {
        $klassement = $this->getJson("/api/rankings/general?season={$this->closedSeason->id}&members=0")->assertOk();
        $statistieken = $this->getJson("/api/seasons/{$this->closedSeason->id}/statistics?members=0")->assertOk();

        $this->assertContains($this->formerMember->id, array_column($klassement->json('data'), 'id'));
        $this->assertContains(
            $this->formerMember->id,
            array_column(array_column($statistieken->json('data'), 'player'), 'id'),
        );
    }

    public function test_de_overzichten_en_de_records_blijven_over_alle_seizoenen_gaan(): void
    {
        $this->getJson('/api/seasons')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/records')->assertOk();
        $this->getJson('/api/archive/seasons')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/archive/seasons/1/standings')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_het_lopende_seizoen_blijft_volledig_open(): void
    {
        $this->getJson('/api/rounds')->assertOk();
        $this->getJson('/api/rounds?include=attendances')->assertOk();
        $this->getJson("/api/rounds/{$this->currentRound->id}")->assertOk();
        $this->getJson("/api/rounds/{$this->currentRound->id}/games")->assertOk();
        $this->getJson("/api/players/{$this->member->id}?include=games,ranking_history")->assertOk();
        $this->getJson("/api/players/{$this->member->id}/games")->assertOk();
        $this->getJson("/api/players/{$this->member->id}/ranking-history")->assertOk();
        $this->getJson("/api/players/{$this->member->id}/pairings")->assertOk();
    }

    // ── De seizoensgrens ──────────────────────────────────────────────────────

    /**
     * Speeldagen, wedstrijden en aanwezigheden van vroeger staan niet in het lijstje
     * van drie dat publiek blijft. `?season=` en een speeldag-id komen op hetzelfde
     * antwoord uit — de middleware leest het seizoen van de route zelf.
     */
    public function test_speeldagen_van_een_afgesloten_seizoen_zijn_dicht(): void
    {
        $this->assertSeizoenGesloten("/api/rounds?season={$this->closedSeason->id}");
        $this->assertSeizoenGesloten("/api/rounds/{$this->closedRound->id}");
        $this->assertSeizoenGesloten("/api/rounds/{$this->closedRound->id}/games");
    }

    /**
     * Deur 1. Deze ene call gaf de aanwezigheid **en de dagscore van elke speler op
     * elke speeldag** van een heel seizoen: wie er wanneer was, wat hij die avond
     * scoorde, en dus zijn aanwezigheidspercentage en zijn vorm. Een vollediger
     * dossier dan de fiche zelf.
     */
    public function test_deur_1_de_aanwezigheden_van_een_heel_afgesloten_seizoen(): void
    {
        $this->assertSeizoenGesloten("/api/rounds?season={$this->closedSeason->id}&include=attendances");
    }

    /**
     * Deur 3. Een wedstrijd verschijnt op de fiche zodra één van de vier nog lid is,
     * en dat is in zowat elke wedstrijd van een afgesloten seizoen zo. Ontdubbeld op
     * `game.id` stond de volledige speeldaggeschiedenis er weer, met alle namen en
     * setstanden.
     */
    public function test_deur_3_de_fiche_reikt_niet_buiten_het_lopende_seizoen(): void
    {
        $this->assertSeizoenGesloten("/api/players/{$this->member->id}?season={$this->closedSeason->id}");
        $this->assertSeizoenGesloten("/api/players/{$this->member->id}/games?season={$this->closedSeason->id}");
        $this->assertSeizoenGesloten("/api/players/{$this->member->id}/ranking-history?season={$this->closedSeason->id}");
        $this->assertSeizoenGesloten("/api/players/{$this->member->id}/pairings?season={$this->closedSeason->id}");
    }

    public function test_een_onbekend_seizoen_blijft_404_en_wordt_geen_403(): void
    {
        // Een typefout in een build-script hoort niet als "afgesloten" te lezen.
        $this->getJson('/api/rounds?season=999')->assertNotFound();
        $this->getJson("/api/players/{$this->member->id}?season=999")->assertNotFound();
        $this->getJson('/api/rounds?season[]=1')->assertNotFound();
    }

    // ── De ledengrens ─────────────────────────────────────────────────────────

    /**
     * De vier fiche-routes van een niet-lid, ook binnen het lopende seizoen. Het
     * antwoord noemt geen naam: wie de fiche van een niet-lid opvraagt hoort niet
     * alsnog te weten wie daar stond.
     */
    public function test_de_fiche_van_een_niet_lid_geeft_403_zonder_naam(): void
    {
        foreach ([
            "/api/players/{$this->formerMember->id}",
            "/api/players/{$this->formerMember->id}/games",
            "/api/players/{$this->formerMember->id}/ranking-history",
            "/api/players/{$this->formerMember->id}/pairings",
        ] as $url) {
            $response = $this->getJson($url)
                ->assertStatus(403)
                ->assertJsonPath('error.code', 'not_a_member');

            $this->assertStringNotContainsString($this->formerMember->last_name, $response->getContent(), $url);
            $this->assertStringNotContainsString($this->formerMember->first_name, $response->getContent(), $url);
        }
    }

    public function test_een_onbestaande_speler_blijft_404_en_wordt_geen_403(): void
    {
        $this->getJson('/api/players/999999')->assertNotFound();
    }

    /**
     * Er is geen bladerbare lijst van iedereen die ooit meespeelde. `?members=0`
     * bestaat hier niet meer en mag ook niet stil iets anders gaan betekenen.
     */
    public function test_de_spelersindex_is_het_ledenbestand(): void
    {
        $ids = array_column($this->getJson('/api/players?members=0')->assertOk()->json('data'), 'id');

        $this->assertNotContains($this->formerMember->id, $ids);
    }

    // ── Wat verwijderd is ─────────────────────────────────────────────────────

    /**
     * Deur 2, plus de speeldagen van 2009-2023. Deze routes zijn verwijderd en niet
     * achter de gate geparkeerd: een route die 403 geeft is een uitnodiging, en git
     * onthoudt het wel.
     */
    public function test_de_verwijderde_archiefroutes_bestaan_niet_meer(): void
    {
        $this->getJson('/api/archive/seasons/1/rounds')->assertNotFound();
        $this->getJson('/api/archive/seasons/1/rounds?include=games')->assertNotFound();
        $this->getJson('/api/archive/rounds/1')->assertNotFound();
        // Deur 2: 192 spelers mét hun seizoenen in één call, waarvan er 114 vertrokken
        // zijn — de eindstand gekanteld, niet per seizoen maar per persoon.
        $this->getJson('/api/archive/players')->assertNotFound();
        $this->getJson('/api/archive/players?include=seasons')->assertNotFound();
        $this->getJson('/api/archive/players/1')->assertNotFound();
    }

    // ── Wat er van de geschiedenis overblijft ─────────────────────────────────

    /**
     * De seizoenstabel op de fiche van een lid: per afgesloten seizoen de vijf
     * kolommen van de eindstand, over beide generaties, chronologisch. Niet
     * openklapbaar naar speeldagen — dat is precies wat de gate hierboven weghoudt.
     */
    public function test_de_fiche_van_een_lid_geeft_de_seizoenstabel_van_vroeger(): void
    {
        $seizoenen = $this->getJson("/api/players/{$this->member->id}")->assertOk()->json('data.seasons');

        $this->assertSame(['2013 - 2014', '2025 - 2026'], array_column($seizoenen, 'season_name'));
        $this->assertSame([true, false], array_column($seizoenen, 'is_archive'));

        // Het lopende seizoen staat niet in de tabel: dat staat volledig op de fiche
        // zelf, in `statistics` en `meta.season`.
        $this->assertNotContains('2026 - 2027', array_column($seizoenen, 'season_name'));

        $this->assertSame(
            ['season_id', 'season_name', 'is_archive', 'rank', 'average', 'sets', 'games', 'rounds'],
            array_keys($seizoenen[0]),
        );
    }

    /**
     * "Dezelfde rijen als op de fiche, andere as" — dus dezelfde plaats. De bevroren
     * `player_round_statistics.rank` kan dat niet zijn: die is geteld over de leden
     * van het moment van berekenen, en de gepubliceerde stand bevat ook wie
     * ondertussen gestopt is. Zonder deze test claimt de fiche een andere plaats dan
     * de tabel waarnaar ze verwijst.
     */
    public function test_de_plaats_op_de_fiche_is_die_uit_de_gepubliceerde_eindstand(): void
    {
        // Een beheerder verbetert een oude uitslag, dus het afgesloten seizoen wordt
        // opnieuw doorgerekend. writeRanks() telt dan enkel de leden: het ex-lid
        // verliest zijn plaats en iedereen onder hem schuift een rij op.
        app(SeasonCalculator::class)->calculate($this->closedSeason);

        $stand = $this->getJson("/api/rankings/general?season={$this->closedSeason->id}&members=0")
            ->assertOk()
            ->json('data');
        $verwacht = collect($stand)->firstWhere('id', $this->member->id);

        $bevroren = DB::table('player_round_statistics')
            ->where('round_id', $this->closedRound->id)
            ->where('player_id', $this->member->id)
            ->value('rank');

        // Precies het geval waar de twee bronnen uiteenlopen; slaagt deze assertie
        // niet, dan bewijst de test hieronder niets.
        $this->assertNotSame((int) $bevroren, $verwacht['rank']);

        $rij = collect($this->getJson("/api/players/{$this->member->id}")->json('data.seasons'))
            ->firstWhere('season_name', '2025 - 2026');

        $this->assertSame($verwacht['rank'], $rij['rank']);
        $this->assertSame($verwacht['average'], $rij['average']);
    }

    /**
     * Valt een seizoen uit de eindstand, dan valt het ook uit de seizoenstabel op de
     * fiche. Anders claimt de fiche deelname die de eindstand ontkent.
     */
    public function test_een_seizoen_zonder_eindgemiddelde_staat_niet_op_de_fiche(): void
    {
        DB::table('player_round_statistics')
            ->where('round_id', $this->closedRound->id)
            ->where('player_id', $this->member->id)
            ->update(['average' => null]);

        $seizoenen = $this->getJson("/api/players/{$this->member->id}")->assertOk()->json('data.seasons');

        $this->assertNotContains('2025 - 2026', array_column($seizoenen, 'season_name'));
        $this->assertNotContains(
            $this->member->id,
            array_column($this->getJson("/api/rankings/general?season={$this->closedSeason->id}&members=0")->json('data'), 'id'),
        );
    }

    private function assertSeizoenGesloten(string $url): void
    {
        $this->getJson($url)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'season_closed');
    }

    /**
     * Eén seizoen met één volledig gespeelde speeldag. De GameObserver herberekent
     * het seizoen, dus na deze methode staan de gemiddelden en de ranks in de
     * databank.
     *
     * @return array{Season, Round}
     */
    private function seizoenMetSpeeldag(string $naam, string $datum): array
    {
        $season = Season::create(['name' => $naam]);
        $round = $season->rounds()->create(['number' => 1, 'date' => $datum]);

        $ids = [];
        foreach (range(1, 4) as $index) {
            $player = Player::firstOrCreate(
                ['first_name' => "Speler{$index}", 'last_name' => 'Test'],
                [
                    'gender' => $index === 1 ? 'female' : 'male',
                    'birth_date' => '1995-01-01',
                    'double_ranking' => 100,
                    'plays_competition' => true,
                    'is_member' => true,
                ],
            );
            $ids[$index] = $player->id;

            PlayerSeasonStatistic::create([
                'season_id' => $season->id,
                'player_id' => $player->id,
                'base_points' => 19 + $index / 10,
            ]);
        }

        Game::create([
            'round_id' => $round->id,
            'player1_id' => $ids[1], 'player2_id' => $ids[2],
            'player3_id' => $ids[3], 'player4_id' => $ids[4],
            'set1_home' => 15, 'set1_away' => 11,
            'set2_home' => 15, 'set2_away' => 11,
            'set3_home' => 15, 'set3_away' => 11,
        ]);

        return [$season, $round];
    }

    /** Eén gearchiveerd seizoen waarin het huidige lid meespeelde. */
    private function seedArchief(): void
    {
        DB::table('archive_players')->insert([
            'id' => 1, 'player_id' => $this->member->id, 'first_name' => 'Speler3',
            'last_name' => 'Test', 'gender' => 'Man', 'ranking' => 'D',
        ]);
        DB::table('archive_seasons')->insert([
            'id' => 1, 'name' => '2013 - 2014', 'source' => 'intra', 'source_id' => 1,
        ]);
        DB::table('archive_rounds')->insert([
            'id' => 1, 'archive_season_id' => 1, 'number' => 1, 'date' => '2013-10-02',
            'average_absent' => 15.5, 'source' => 'intra', 'source_id' => 1,
        ]);
        DB::table('archive_player_season_statistics')->insert([
            'archive_season_id' => 1, 'archive_player_id' => 1, 'base_points' => 19.0,
            'sets_played' => 2, 'sets_won' => 2, 'points_played' => 64, 'points_won' => 42,
            'games_played' => 1, 'games_won' => 1, 'rounds_present' => 1,
        ]);
        DB::table('archive_player_round_statistics')->insert([
            'archive_round_id' => 1, 'archive_player_id' => 1, 'average' => 19.5,
        ]);
    }
}
