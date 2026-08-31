<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Player;
use App\Models\PlayerRoundStatistic;
use App\Models\PlayerSeasonStatistic;
use App\Models\Round;
use App\Models\Season;
use App\Services\SeasonCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\PlaysToPoints;
use Tests\TestCase;

/**
 * Legt het publieke API-contract vast: dit is de vorm die de clubwebsite
 * consumeert.
 *
 * De conventies, en ze gelden overal:
 *   - veldnamen in snake_case, booleans als echte booleans;
 *   - collecties in `data`, met `meta` voor seizoen en speeldag;
 *   - filters als queryparameters, niet als aparte routes;
 *   - een onbekend seizoen, speeldag of include faalt, met een status die zegt wat.
 *
 * Draait voor sets tot 15 en tot 21: zie {@see PublicApiPlayedTo21Test}.
 */
class PublicApiTest extends TestCase
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
        $this->round = $this->season->rounds()->create(['number' => 1, 'date' => '2026-09-01']);

        foreach (range(1, 4) as $index) {
            $player = Player::create([
                'first_name' => "Speler{$index}",
                'last_name' => 'Test',
                'gender' => $index === 1 ? 'female' : 'male',
                'birth_date' => $index === 2 ? '1970-01-01' : '1995-01-01',
                'double_ranking' => 100,
                'plays_competition' => $index !== 3,
                'is_member' => true,
            ]);
            $this->players[$index] = $player;

            PlayerSeasonStatistic::create([
                'season_id' => $this->season->id,
                'player_id' => $player->id,
                'base_points' => $this->format->basePoints($index),
            ]);
        }

        // Een volledige game laat de GameObserver het seizoen herberekenen, dus na
        // deze regel is de speeldag berekend en staan de ranks in de databank.
        Game::create([
            'round_id' => $this->round->id,
            'player1_id' => $this->players[1]->id,
            'player2_id' => $this->players[2]->id,
            'player3_id' => $this->players[3]->id,
            'player4_id' => $this->players[4]->id,
            ...$this->format->straightSets(),
        ]);
    }

    public function test_klassement_bevat_de_vier_categorieen_met_meta(): void
    {
        $this->getJson('/api/rankings')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'general' => [['id', 'first_name', 'last_name', 'full_name', 'average', 'rank', 'difference']],
                    'women',
                    'veterans',
                    'recreants',
                ],
                'meta' => ['season' => ['id', 'name'], 'round' => ['id', 'number', 'date']],
            ])
            ->assertJsonPath('meta.season.name', '2026 - 2027')
            ->assertJsonPath('meta.season.points_per_set', $this->format->value())
            // Dit verving /rounds/latestCalculated: de stand zegt zelf na welke
            // speeldag ze geldt.
            ->assertJsonPath('meta.round.id', $this->round->id)
            ->assertJsonPath('meta.round.number', 1);
    }

    public function test_klassementcategorie_kan_via_pad_of_parameter(): void
    {
        $viaPad = $this->getJson('/api/rankings/women')->assertOk();

        $this->assertSame([$this->players[1]->id], array_column($viaPad->json('data'), 'id'));
        $this->assertSame('women', $viaPad->json('meta.category'));

        $viaParameter = $this->getJson('/api/rankings?category=women')->assertOk();

        $this->assertSame($viaPad->json('data'), $viaParameter->json('data'));
    }

    public function test_klassement_geeft_de_volledige_naam_mee(): void
    {
        $this->getJson('/api/rankings/general')
            ->assertOk()
            ->assertJsonPath('data.0.full_name', fn (string $naam): bool => str_ends_with($naam, ' Test'));
    }

    public function test_klassement_is_te_beperken_met_limit(): void
    {
        $this->getJson('/api/rankings/general?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_onbekende_klassementcategorie_geeft_404(): void
    {
        $this->getJson('/api/rankings/onzin')->assertNotFound();
    }

    public function test_onbekend_seizoen_geeft_404_en_niet_stil_het_huidige(): void
    {
        $this->getJson('/api/rankings/general?season=999')->assertNotFound();
        $this->getJson('/api/rounds?season=999')->assertNotFound();
        $this->getJson('/api/seasons/999/statistics')->assertNotFound();
    }

    public function test_speeldagen_dragen_hun_eigen_tellingen(): void
    {
        PlayerRoundStatistic::where('round_id', $this->round->id)
            ->where('player_id', $this->players[1]->id)
            ->update(['is_present' => true]);

        $this->getJson('/api/rounds')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 1)
            ->assertJsonPath('data.0.date', '2026-09-01')
            // Echte boolean, geen 1 of "1": de site vergeleek dit met een string.
            ->assertJsonPath('data.0.is_calculated', true)
            ->assertJsonPath('data.0.games_count', 1)
            // De site rekende dit zelf uit als games * 4. Nu staat het hier.
            ->assertJsonPath('data.0.players_present', 1)
            ->assertJsonPath('data.0.players_drawn_out', 0)
            ->assertJsonPath('meta.season.id', $this->season->id);
    }

    public function test_speeldagen_zijn_op_berekend_te_filteren(): void
    {
        $this->season->rounds()->create(['number' => 2, 'date' => '2026-09-15']);

        $this->getJson('/api/rounds')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/rounds?calculated=1')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/rounds?calculated=0')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 2);
    }

    public function test_speeldagen_geven_op_vraag_hun_aanwezigheden_mee(): void
    {
        PlayerRoundStatistic::where('round_id', $this->round->id)
            ->where('player_id', $this->players[1]->id)
            ->update(['is_present' => true]);

        $this->getJson('/api/rounds')
            ->assertOk()
            // Zonder include blijft de lijst kaal: de meeste pagina's hebben er
            // enkel de tellingen van nodig.
            ->assertJsonMissingPath('data.0.attendances');

        $this->getJson('/api/rounds?include=attendances')
            ->assertOk()
            ->assertJsonCount(4, 'data.0.attendances')
            ->assertJsonStructure([
                'data' => [['attendances' => [[
                    'player' => ['id', 'first_name', 'last_name', 'full_name'],
                    'is_present', 'is_drawn_out', 'day_score', 'average', 'rank',
                ]]]],
            ])
            // Aanwezigheid staat er als echte boolean in en hoeft dus niet uit
            // iemands wedstrijden afgeleid te worden.
            ->assertJsonPath('data.0.attendances.0.is_present', true);
    }

    public function test_de_aanwezigheden_op_de_lijst_zijn_dezelfde_als_op_de_speeldag(): void
    {
        $lijst = $this->getJson('/api/rounds?include=attendances')->assertOk();
        $detail = $this->getJson("/api/rounds/{$this->round->id}")->assertOk();

        // Eén vorm, twee endpoints: een consument die de lijst gebruikt in plaats
        // van een call per speeldag mag geen andere velden krijgen.
        $this->assertSame($detail->json('data.attendances'), $lijst->json('data.0.attendances'));
    }

    public function test_de_aanwezigheden_kosten_geen_query_per_speeldag(): void
    {
        $queries = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();

            $this->getJson('/api/rounds?include=attendances')->assertOk();

            $aantal = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $aantal;
        };

        $metEen = $queries();

        $this->season->rounds()->create(['number' => 2, 'date' => '2026-09-15']);
        $this->season->rounds()->create(['number' => 3, 'date' => '2026-09-22']);

        // Dit is de hele reden dat de include bestaat: alles wordt in één keer
        // ingeladen, dus drie speeldagen kosten evenveel queries als één.
        $this->assertSame($metEen, $queries());
    }

    public function test_een_include_op_een_route_die_er_geen_kent_geeft_422(): void
    {
        // Stil negeren was hier de duurste fout: wie dit op een lijst zette kreeg
        // de kale lijst terug en haalde de rest dan per speler op.
        $this->getJson('/api/players?include=games')->assertStatus(422);
        $this->getJson("/api/rounds/{$this->round->id}?include=attendances")->assertStatus(422);
        $this->getJson('/api/seasons?include=rounds')->assertStatus(422);
        // Een array in de querystring hoort ook een 422 te zijn, geen 500.
        $this->getJson('/api/rounds?include[]=onzin')->assertStatus(422);
    }

    public function test_speeldagdetail_geeft_de_opstelling_per_set(): void
    {
        $response = $this->getJson("/api/rounds/{$this->round->id}")->assertOk();

        $spelers = array_column($response->json('data.games.0.players'), 'id');
        $this->assertCount(4, $spelers);

        // De rotatie staat in de API en niet meer in de frontend:
        // set 1 = P1+P2 vs P3+P4, set 2 = P1+P3 vs P2+P4, set 3 = P1+P4 vs P2+P3.
        $response
            ->assertJsonPath('data.games.0.sets.0.home.player_ids', [$spelers[0], $spelers[1]])
            ->assertJsonPath('data.games.0.sets.0.away.player_ids', [$spelers[2], $spelers[3]])
            ->assertJsonPath('data.games.0.sets.1.home.player_ids', [$spelers[0], $spelers[2]])
            ->assertJsonPath('data.games.0.sets.2.home.player_ids', [$spelers[0], $spelers[3]])
            ->assertJsonPath('data.games.0.sets.0.home.score', $this->format->win())
            ->assertJsonPath('data.games.0.sets.0.away.score', $this->format->lose())
            ->assertJsonPath('data.games.0.sets.0.is_played', true)
            ->assertJsonPath('data.games.0.sets.0.winner', 'home')
            ->assertJsonPath('data.games.0.is_complete', true)
            ->assertJsonPath('data.games.0.round.number', 1);
    }

    public function test_een_ongespeelde_set_is_null_en_niet_nul(): void
    {
        $round = $this->season->rounds()->create(['number' => 2, 'date' => '2026-09-15']);
        $game = Game::create([
            'round_id' => $round->id,
            'player1_id' => $this->players[1]->id,
            'player2_id' => $this->players[2]->id,
            'player3_id' => $this->players[3]->id,
            'player4_id' => $this->players[4]->id,
            ...$this->format->firstSetOnly(),
        ]);

        $this->getJson("/api/rounds/{$round->id}")
            ->assertOk()
            ->assertJsonPath('data.games.0.sets.1.is_played', false)
            ->assertJsonPath('data.games.0.sets.1.home.score', null)
            ->assertJsonPath('data.games.0.sets.1.winner', null)
            ->assertJsonPath('data.games.0.is_complete', false);

        $this->assertSame($game->id, $this->getJson("/api/rounds/{$round->id}")->json('data.games.0.id'));
    }

    public function test_speeldagdetail_bevat_de_aanwezigheden_met_naam(): void
    {
        PlayerRoundStatistic::where('round_id', $this->round->id)
            ->where('player_id', $this->players[2]->id)
            ->update(['is_present' => true, 'is_drawn_out' => true]);

        // Laatst aangemaakt, dus achteraan in databankvolgorde, maar alfabetisch
        // vooraan. Zonder die botsing zou de sorteercontrole hieronder ook slagen
        // met een sortering die niets doet.
        $this->extraSpeler('Aaron');
        app(SeasonCalculator::class)->calculate($this->season);

        $response = $this->getJson("/api/rounds/{$this->round->id}")->assertOk();

        // De site moest hiervoor eerst /players ophalen; nu staat de naam erbij.
        $response->assertJsonStructure([
            'data' => ['attendances' => [['player' => ['id', 'full_name'], 'is_present', 'is_drawn_out', 'average', 'rank']]],
        ]);

        // Op naam gesorteerd, niet in databankvolgorde.
        $namen = array_column(array_column($response->json('data.attendances'), 'player'), 'full_name');
        $gesorteerd = $namen;
        sort($gesorteerd);
        $this->assertSame($gesorteerd, $namen);

        $uitgeloot = collect($response->json('data.attendances'))->firstWhere('is_drawn_out', true);
        $this->assertSame($this->players[2]->id, $uitgeloot['player']['id']);
        $this->assertTrue($uitgeloot['is_present']);
    }

    /**
     * De setstanden zijn drie keer win-lose, dus speler 1 wint alle drie zijn sets
     * (dagscore = setmaximum) en de andere drie winnen er één. Het
     * verliezersgemiddelde van de speeldag is de losing score.
     */
    public function test_speeldagdetail_geeft_de_dagscore_per_speler(): void
    {
        $aanwezigheden = collect($this->getJson("/api/rounds/{$this->round->id}")->json('data.attendances'))
            ->keyBy('player.id');

        $this->assertSame($this->format->winnerDayScore(), (float) $aanwezigheden[$this->players[1]->id]['day_score']);
        $this->assertSame($this->format->otherDayScore(), (float) $aanwezigheden[$this->players[2]->id]['day_score']);
    }

    public function test_dagscore_van_een_afwezige_en_een_uitgelote(): void
    {
        $afwezig = $this->extraSpeler('Afwezig');
        $uitgeloot = $this->extraSpeler('Uitgeloot');

        // De vlag moet er staan vóór de herberekening: SeasonCalculator leest hem om
        // te weten welke speeldag voor die speler niet meetelt.
        PlayerRoundStatistic::create([
            'round_id' => $this->round->id,
            'player_id' => $uitgeloot->id,
            'is_present' => true,
            'is_drawn_out' => true,
        ]);

        app(SeasonCalculator::class)->calculate($this->season);

        $response = $this->getJson("/api/rounds/{$this->round->id}")->assertOk();
        $aanwezigheden = collect($response->json('data.attendances'))->keyBy('player.id');

        $this->assertSame($this->format->absentAverage(), (float) $response->json('data.average_absent'));
        // Afwezig: het verliezersgemiddelde van die speeldag.
        $this->assertSame($this->format->absentAverage(), (float) $aanwezigheden[$afwezig->id]['day_score']);
        // Uitgeloot zonder game: die speeldag telt niet mee, dus geen dagscore.
        $this->assertNull($aanwezigheden[$uitgeloot->id]['day_score']);
    }

    /**
     * Met de dagscore erbij is het gemiddelde na een speeldag na te rekenen:
     * (basispunten + dagscore) / 2 na de eerste speeldag.
     */
    public function test_rankinggeschiedenis_maakt_de_formule_navolgbaar(): void
    {
        $verloop = $this->getJson("/api/players/{$this->players[1]->id}/ranking-history")->json('data.0');

        $this->assertSame($this->format->winnerDayScore(), (float) $verloop['day_score']);
        $this->assertSame(round(($this->format->basePoints(1) + $this->format->winnerDayScore()) / 2, 2), $verloop['average']);
    }

    /**
     * De rotatie geeft speler 2 één set mét elk van de anderen en twee sets tégen
     * elk van hen. Hij speelt met speler 1 in set 1 (gewonnen) en staat in de
     * sets 2 en 3 tegen hem, beide verloren.
     */
    public function test_partner_en_tegenstanderbalans(): void
    {
        $data = $this->getJson("/api/players/{$this->players[2]->id}/pairings")->assertOk()->json('data');
        $rijen = collect($data)->keyBy('player.id');

        $this->assertCount(3, $rijen);
        // Op aantal avonden, dan gewonnen sets als partner, dan naam. Allemaal 1
        // avond hier, dus speler 1 staat vooraan met 1 gewonnen set als partner.
        $this->assertSame($this->players[1]->id, $data[0]['player']['id']);

        $tegenSpeler1 = $rijen[$this->players[1]->id];
        $this->assertSame(1, $tegenSpeler1['games']);
        $this->assertSame(['sets' => 1, 'sets_won' => 1], $tegenSpeler1['as_partner']);
        $this->assertSame(['sets' => 2, 'sets_won' => 0], $tegenSpeler1['as_opponent']);
        $this->assertSame('Speler1 Test', $tegenSpeler1['player']['full_name']);
    }

    /**
     * Pint de eigenschap van het format vast waardoor deze statistiek geen keuzes
     * vraagt: per gespeelde avond één set met elke ander, en twee sets tegen elk.
     */
    public function test_elke_avond_levert_een_set_met_en_twee_tegen_elke_ander(): void
    {
        foreach ($this->players as $player) {
            foreach ($this->getJson("/api/players/{$player->id}/pairings")->json('data') as $rij) {
                $this->assertSame($rij['games'], $rij['as_partner']['sets']);
                $this->assertSame($rij['games'] * 2, $rij['as_opponent']['sets']);
            }
        }
    }

    /**
     * Speler 2 speelt een tweede avond met speler 3 erbij, niet met speler 1. Dan
     * moet speler 3 bovenaan staan op aantal avonden — een sortering die niets doet
     * zou speler 1 vooraan houden, want die kwam als eerste in de telling.
     */
    public function test_partnerbalans_staat_op_aantal_avonden_gesorteerd(): void
    {
        $round = $this->season->rounds()->create(['number' => 2, 'date' => '2026-09-15']);
        Game::create([
            'round_id' => $round->id,
            'player1_id' => $this->players[2]->id,
            'player2_id' => $this->players[3]->id,
            'player3_id' => $this->extraSpeler('Zoe')->id,
            'player4_id' => $this->extraSpeler('Yves')->id,
            ...$this->format->straightSets(),
        ]);

        $data = $this->getJson("/api/players/{$this->players[2]->id}/pairings")->assertOk()->json('data');

        $this->assertSame($this->players[3]->id, $data[0]['player']['id']);
        $this->assertSame(2, $data[0]['games']);
    }

    public function test_partnerbalans_laat_onvolledige_wedstrijden_buiten_de_telling(): void
    {
        $round = $this->season->rounds()->create(['number' => 2, 'date' => '2026-09-15']);
        Game::create([
            'round_id' => $round->id,
            'player1_id' => $this->players[1]->id,
            'player2_id' => $this->players[2]->id,
            'player3_id' => $this->players[3]->id,
            'player4_id' => $this->players[4]->id,
            ...$this->format->firstSetOnly(),
        ]);

        $rijen = collect($this->getJson("/api/players/{$this->players[2]->id}/pairings")->json('data'))
            ->keyBy('player.id');

        // Nog steeds één avond: zonder alle drie de sets valt er niets te vergelijken.
        $this->assertSame(1, $rijen[$this->players[1]->id]['games']);
    }

    public function test_klassement_kan_ook_wie_gestopt_is_bevatten(): void
    {
        $kampioen = $this->players[1];
        $this->assertSame($kampioen->id, $this->getJson('/api/rankings/general')->json('data.0.id'));

        $kampioen->update(['is_member' => false]);

        $this->getJson('/api/rankings/general')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.id', fn (int $id): bool => $id !== $kampioen->id);

        // Zonder dit zou de erelijst van een afgesloten seizoen de verkeerde
        // kampioen tonen zodra die de club verlaat.
        $this->getJson('/api/rankings/general?members=0')
            ->assertOk()
            ->assertJsonCount(4, 'data')
            ->assertJsonPath('data.0.id', $kampioen->id);
    }

    public function test_wedstrijden_van_een_speeldag_zijn_apart_op_te_vragen(): void
    {
        $this->getJson("/api/rounds/{$this->round->id}/games")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.round.number', 1);
    }

    public function test_spelerslijst_gebruikt_de_enum_waarden(): void
    {
        $response = $this->getJson('/api/players')->assertOk();

        // Niet meer 'Woman'/'Man' uit de legacy-API: dit is de waarde die ook in
        // de databank en in de zaal-app staat.
        $this->assertSame('female', $response->json('data.0.gender'));
        $this->assertSame('male', $response->json('data.1.gender'));

        $response
            ->assertJsonPath('data.1.is_veteran', true)
            ->assertJsonPath('data.2.is_recreant', true)
            ->assertJsonPath('data.0.plays_competition', true);
    }

    /**
     * De lijst is het huidige ledenbestand, en ?members=0 bestaat hier niet meer:
     * dat was een bladerbare lijst van iedereen die ooit meespeelde. Een onbekende
     * parameter mag geen tweede betekenis krijgen, dus hij wordt gewoon genegeerd —
     * de lijst blijft het ledenbestand.
     */
    public function test_spelerslijst_is_het_ledenbestand_en_kent_geen_members_parameter(): void
    {
        $this->players[4]->update(['is_member' => false]);

        $this->getJson('/api/players')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/players?members=0')->assertOk()->assertJsonCount(3, 'data');
    }

    /**
     * De twee helften van een eindstand: /rankings geeft plaats en gemiddelde,
     * /seasons/{id}/statistics de sets, matchen en aanwezigheden. Ze horen dus over
     * dezelfde spelers te gaan. Vóór fase 12 deed enkel de eerste de eis "een
     * gemiddelde op de laatste berekende speeldag", waardoor 2025-2026 er 88 gaf en
     * 121 — voor 33 spelers bestond de helft van de vijf kolommen niet.
     */
    public function test_eindstand_en_seizoensstatistieken_gaan_over_dezelfde_spelers(): void
    {
        $zonderGemiddelde = $this->extraSpeler('Zonder');
        $this->players[4]->update(['is_member' => false]);

        foreach ([true, false] as $membersOnly) {
            $parameter = $membersOnly ? '' : '?members=0';

            $klassement = array_column(
                $this->getJson("/api/rankings/general{$parameter}")->assertOk()->json('data'), 'id'
            );
            $statistieken = array_column(array_column(
                $this->getJson("/api/seasons/current/statistics{$parameter}")->assertOk()->json('data'), 'player'
            ), 'id');

            sort($klassement);
            sort($statistieken);

            $this->assertSame($klassement, $statistieken, 'members='.($membersOnly ? '1' : '0'));
            $this->assertNotContains($zonderGemiddelde->id, $statistieken);
        }
    }

    public function test_seizoenen_tellen_de_lengte_van_de_eindstand(): void
    {
        $this->extraSpeler('Zonder');

        // Vijf seizoensrijen, vier spelers met een gemiddelde na speeldag 1. Het
        // getal komt naast een link naar de eindstand, dus telt het die stand.
        $this->assertSame(5, PlayerSeasonStatistic::where('season_id', $this->season->id)->count());

        $this->getJson('/api/seasons')
            ->assertOk()
            ->assertJsonPath('data.0.players_count', 4);
    }

    public function test_spelerdetail_geeft_enkel_de_tellers_zonder_include(): void
    {
        $this->getJson("/api/players/{$this->players[1]->id}")
            ->assertOk()
            ->assertJsonPath('data.first_name', 'Speler1')
            ->assertJsonPath('data.full_name', 'Speler1 Test')
            ->assertJsonStructure([
                'data' => [
                    'id', 'first_name', 'last_name', 'full_name', 'gender', 'bonus_points',
                    'statistics' => [
                        'base_points',
                        'points' => ['won', 'lost', 'total'],
                        'sets' => ['won', 'lost', 'total'],
                        'games' => ['total'],
                        'rounds' => ['present'],
                    ],
                ],
                'meta' => ['season' => ['id', 'name']],
            ])
            ->assertJsonMissingPath('data.games')
            ->assertJsonMissingPath('data.ranking_history');
    }

    public function test_spelerdetail_haalt_op_vraag_wedstrijden_en_verloop_mee(): void
    {
        $this->getJson("/api/players/{$this->players[1]->id}?include=games,ranking_history")
            ->assertOk()
            ->assertJsonCount(1, 'data.games')
            ->assertJsonCount(1, 'data.ranking_history')
            ->assertJsonStructure(['data' => ['ranking_history' => [['round_id', 'number', 'date', 'average', 'rank']]]]);
    }

    public function test_een_onbekende_include_geeft_422(): void
    {
        $this->getJson("/api/players/{$this->players[1]->id}?include=alles")->assertStatus(422);
    }

    public function test_wedstrijden_en_verloop_bestaan_ook_als_eigen_resource(): void
    {
        $this->getJson("/api/players/{$this->players[1]->id}/games")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->getJson("/api/players/{$this->players[1]->id}/ranking-history")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.number', 1);
    }

    /**
     * De rank in de historiek komt uit player_round_statistics.rank en moet
     * dezelfde zijn als die in het klassement. Vroeger werd hij op twee plaatsen
     * apart geteld, met verschillende filters.
     */
    public function test_de_rank_in_de_historiek_is_dezelfde_als_in_het_klassement(): void
    {
        $klassement = collect($this->getJson('/api/rankings/general')->json('data'));

        foreach ($this->players as $player) {
            $verwacht = $klassement->firstWhere('id', $player->id)['rank'];
            $historiek = $this->getJson("/api/players/{$player->id}/ranking-history")->json('data');

            $this->assertSame($verwacht, $historiek[0]['rank'], "rank van speler {$player->id}");
        }
    }

    public function test_de_bevroren_rank_verschuift_niet_als_iemand_stopt(): void
    {
        $klassement = collect($this->getJson('/api/rankings/general')->json('data'));
        $eerste = $klassement->first();
        $laatste = $klassement->last();

        $rankVoor = $this->getJson("/api/players/{$laatste['id']}/ranking-history")->json('data.0.rank');
        $this->assertSame($klassement->count(), $rankVoor);

        // De nummer één stopt. Het klassement van vandaag schuift op, de historiek
        // van speeldag 1 blijft staan zoals ze toen was.
        Player::findOrFail($eerste['id'])->update(['is_member' => false]);

        $this->assertSame(
            $rankVoor - 1,
            collect($this->getJson('/api/rankings/general')->json('data'))->last()['rank'],
        );
        $this->assertSame(
            $rankVoor,
            $this->getJson("/api/players/{$laatste['id']}/ranking-history")->json('data.0.rank'),
        );
    }

    public function test_seizoenen_zeggen_welk_het_lopende_is(): void
    {
        $this->getJson('/api/seasons')
            ->assertOk()
            ->assertJsonPath('data.0.name', '2026 - 2027')
            ->assertJsonPath('data.0.rounds_count', 1)
            ->assertJsonPath('data.0.points_per_set', $this->format->value())
            ->assertJsonPath('meta.current_season_id', $this->season->id);
    }

    public function test_seizoensstatistieken_via_current_of_via_id(): void
    {
        PlayerRoundStatistic::where('player_id', $this->players[4]->id)->delete();
        PlayerSeasonStatistic::where('player_id', $this->players[1]->id)->update(['rounds_present' => 99]);

        $viaCurrent = $this->getJson('/api/seasons/current/statistics')->assertOk();

        $this->assertSame($this->players[1]->id, $viaCurrent->json('data.0.player.id'));
        $viaCurrent
            ->assertJsonPath('meta.season.id', $this->season->id)
            ->assertJsonStructure([
                'data' => [['player' => ['id', 'full_name'], 'statistics' => ['sets' => ['won', 'lost', 'total']]]],
            ]);

        $this->assertSame(
            $viaCurrent->json('data'),
            $this->getJson("/api/seasons/{$this->season->id}/statistics")->json('data'),
        );
    }

    public function test_seizoensstatistieken_kunnen_ook_wie_gestopt_is_bevatten(): void
    {
        $this->players[4]->update(['is_member' => false]);

        $this->getJson('/api/seasons/current/statistics')->assertOk()->assertJsonCount(3, 'data');
        // Nodig voor een pagina over een afgesloten seizoen: wie toen meedeed hoort
        // in die eindstand, ook al is hij nu geen lid meer.
        $this->getJson('/api/seasons/current/statistics?members=0')->assertOk()->assertJsonCount(4, 'data');
    }

    /** Lid met een seizoensstatistiek maar zonder wedstrijd op deze speeldag. */
    private function extraSpeler(string $naam): Player
    {
        $player = Player::create([
            'first_name' => $naam,
            'last_name' => 'Test',
            'gender' => 'male',
            'birth_date' => '1995-01-01',
            'double_ranking' => 100,
            'plays_competition' => true,
            'is_member' => true,
        ]);

        PlayerSeasonStatistic::create([
            'season_id' => $this->season->id,
            'player_id' => $player->id,
            'base_points' => $this->format->startingBasePoints(),
        ]);

        return $player;
    }

    public function test_publieke_api_vereist_geen_login_en_mag_een_minuut_gecachet_worden(): void
    {
        $this->assertGuest();

        $this->getJson('/api/rankings')
            ->assertOk()
            ->assertHeader('Cache-Control', 'max-age=60, public');

        $this->getJson('/api/players')->assertOk();
    }
}
