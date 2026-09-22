<?php

namespace Tests\Feature;

use App\Models\Game;
use App\Models\Player;
use App\Models\PlayerSeasonStatistic;
use App\Models\PushMessage;
use App\Models\PushSubscription;
use App\Models\Round;
use App\Models\Season;
use App\Services\SeasonCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FakesPushService;
use Tests\Concerns\PlaysToPoints;
use Tests\TestCase;

/*
 * Het automatische intraclub-bericht. De valkuilen staan in RoundNotifier: de
 * vlag is_calculated wipt op een avond meermaals, een herberekening na een
 * correctie mag niet opnieuw sturen, en een import van twintig speeldagen mag
 * geen twintig berichten geven.
 */
class RoundPushNotificationTest extends TestCase
{
    use FakesPushService;
    use PlaysToPoints;
    use RefreshDatabase;

    private Season $season;

    /** @var array<int, Player> */
    private array $players = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootFormat();
        $this->fakePushService([201, 201, 201, 201, 201, 201]);

        PushSubscription::factory()->topics(['intraclub'])->create();
        PushSubscription::factory()->topics(['club'])->create();

        $this->season = Season::create([
            'name' => '2026 - 2027',
            'points_per_set' => $this->format->pointsPerSet,
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

    public function test_de_laatste_score_van_de_avond_verstuurt_een_bericht_naar_de_intraclub_abonnees(): void
    {
        $round = $this->round(date: today());
        $first = $this->createGame($round, [1, 2, 3, 4], complete: false);
        $second = $this->createGame($round, [5, 6, 7, 8], complete: false);

        $first->update($this->format->straightSets());
        $this->assertDatabaseCount('push_messages', 0);

        $second->update($this->format->straightSets());

        $message = PushMessage::sole();
        $this->assertSame('intraclub', $message->topic);
        $this->assertSame('Intraclub: speeldag 1 berekend', $message->title);
        $this->assertSame('De nieuwe stand na '.today()->translatedFormat('j F').' staat online.', $message->body);
        $this->assertSame("https://www.bclandegem.be/intraclub/speeldag/?id={$round->id}", $message->url);
        $this->assertSame($round->id, $message->round_id);
        $this->assertNull($message->user_id);
        $this->assertNotNull($round->fresh()->push_notified_at);

        // Verstuurd (sync queue in de tests), enkel naar het intraclub-abonnement.
        $this->assertSame(1, $message->fresh()->sent_count);
        $this->assertCount(1, $this->pushedEndpoints());
    }

    public function test_een_tweede_golf_matchen_en_een_correctie_sturen_niet_opnieuw(): void
    {
        $round = $this->round(date: today());
        $this->createGame($round, [1, 2, 3, 4], complete: true);
        $this->assertDatabaseCount('push_messages', 1);

        // Golf twee: de speeldag valt uit de stand en komt er weer in.
        $second = $this->createGame($round, [5, 6, 7, 8], complete: false);
        $this->assertFalse($round->fresh()->is_calculated);
        $second->update($this->format->straightSets());
        $this->assertTrue($round->fresh()->is_calculated);

        // Een correctie achteraf en een handmatige herberekening.
        $second->update(['set1_home' => 15, 'set1_away' => 3]);
        app(SeasonCalculator::class)->calculate($this->season);

        $this->assertDatabaseCount('push_messages', 1);
    }

    public function test_een_oude_speeldag_krijgt_geen_bericht(): void
    {
        // Zoals bij een import of een reset: de datum ligt (te) lang achter ons.
        $round = $this->round(date: today()->subDays(4));
        $this->createGame($round, [1, 2, 3, 4], complete: true);

        $this->assertTrue($round->fresh()->is_calculated);
        $this->assertDatabaseCount('push_messages', 0);
        $this->assertNull($round->fresh()->push_notified_at);

        // Precies op de grens nog wel.
        $recent = $this->round(number: 2, date: today()->subDays(3));
        $this->createGame($recent, [1, 2, 3, 4], complete: true);
        $this->assertDatabaseCount('push_messages', 1);
    }

    /*
     * Season::current() is het hoogste id. Wie het volgende seizoen aanmaakt
     * vóór de laatste speeldagen gespeeld zijn, mag daarmee het bericht niet
     * stil uitzetten; de datumgrens hierboven doet het werk.
     */
    public function test_het_volgende_seizoen_aanmaken_legt_de_laatste_speeldagen_niet_stil(): void
    {
        $round = $this->round(date: today());
        Season::create(['name' => '2027 - 2028', 'points_per_set' => $this->format->pointsPerSet]);

        $this->createGame($round, [1, 2, 3, 4], complete: true);

        $this->assertDatabaseCount('push_messages', 1);
    }

    public function test_zonder_sleutels_vertrekt_er_niets_en_blijft_de_speeldag_ongemarkeerd(): void
    {
        config(['push.vapid.private_key' => null]);

        $round = $this->round(date: today());
        $this->createGame($round, [1, 2, 3, 4], complete: true);

        $this->assertDatabaseCount('push_messages', 0);
        $this->assertNull($round->fresh()->push_notified_at);
    }

    private function round(int $number = 1, mixed $date = null): Round
    {
        return $this->season->rounds()->create([
            'number' => $number,
            'date' => ($date ?? today())->toDateString(),
        ]);
    }

    /** @param  list<int>  $playerIndexes */
    private function createGame(Round $round, array $playerIndexes, bool $complete): Game
    {
        $scores = $complete
            ? $this->format->straightSets()
            : $this->format->firstSetOnly();

        return $round->games()->create([
            'player1_id' => $this->players[$playerIndexes[0]]->id,
            'player2_id' => $this->players[$playerIndexes[1]]->id,
            'player3_id' => $this->players[$playerIndexes[2]]->id,
            'player4_id' => $this->players[$playerIndexes[3]]->id,
            ...$scores,
        ]);
    }
}
