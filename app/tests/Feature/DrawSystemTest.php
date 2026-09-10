<?php

namespace Tests\Feature;

use App\Enums\DrawSystem;
use App\Models\Game;
use App\Models\Player;
use App\Models\PlayerRoundStatistic;
use App\Models\PlayerSeasonStatistic;
use App\Models\Round;
use App\Models\Season;
use App\Services\DrawService;
use App\Services\SeasonEncounters;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PlaysToPoints;
use Tests\TestCase;

/**
 * De twee lotingsystemen, en waarin ze verschillen.
 *
 * De vormregels (wie meedoet, wie aan de kant blijft, het beschermingsvenster) staan
 * in {@see DrawAndDrawnOutTest} en gelden voor beide systemen; hier staat enkel wat
 * de samenstelling anders maakt.
 *
 * Deze tests meten de uitkomst en niet alleen de vorm. Een vormtest blijft groen als
 * de tie-break omkeert, als het geheugen per ongeluk enkel volledige wedstrijden telt
 * of als een sortering stil niets doet — allemaal fouten die nog steeds keurige
 * viertallen opleveren, maar die het systeem laten ophouden te doen waarvoor het
 * bestaat.
 */
class DrawSystemTest extends TestCase
{
    use PlaysToPoints;
    use RefreshDatabase;

    private Season $season;

    /** @var array<int, Player> */
    private array $players = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->bootFormat();

        $this->season = Season::create([
            'name' => '2026 - 2027',
            'points_per_set' => $this->format->pointsPerSet,
            'draw_system' => DrawSystem::VaryingOpponents,
        ]);
    }

    public function test_sterktegroepen_zetten_de_sterkste_vier_bij_elkaar(): void
    {
        $this->season->update(['draw_system' => DrawSystem::StrengthGroups]);

        // De vier sterksten zijn bewust de vier *laatste* op id. Vallen ze in de
        // eerste game, dan is er echt op gemiddelde gesorteerd; komt {1,2,3,4} eruit,
        // dan draait de loting op de databankvolgorde — precies wat er gebeurde
        // zolang $averages buiten de `use` van de closure viel en dus voor iedereen
        // 0.0 opleverde, stil, want `??` slikt de waarschuwing.
        $this->makePlayers(8);
        $this->setStrength([5 => 8, 6 => 7, 7 => 6, 8 => 5, 1 => 4, 2 => 3, 3 => 2, 4 => 1]);

        $round = $this->roundWithPresentPlayers(1, range(1, 8));

        $games = app(DrawService::class)->draw($round)['games'];

        $this->assertCount(2, $games);
        $this->assertEqualsCanonicalizing($this->ids([5, 6, 7, 8]), $games[0]);
        $this->assertEqualsCanonicalizing($this->ids([1, 2, 3, 4]), $games[1]);
    }

    public function test_wisselende_tegenstanders_zet_niemand_twee_keer_tegen_dezelfde(): void
    {
        // Zestien spelers in vier vaste viertallen. Wie daarna één speler per
        // viertal samenzet, heeft een speeldag zonder één herhaald koppel — dat is
        // precies wat een geheugen dat werkt eruit moet halen.
        $this->makePlayers(16);

        $first = $this->roundWithPresentPlayers(1, range(1, 16));
        foreach ([[1, 2, 3, 4], [5, 6, 7, 8], [9, 10, 11, 12], [13, 14, 15, 16]] as $four) {
            $first->games()->create($this->gamePlayers($four));
        }

        $second = $this->roundWithPresentPlayers(2, range(1, 16));

        // `draw()` bewaart de wedstrijden niet — het geeft voorstellen terug die de
        // zaal nog bevestigt. Ze hier wegschrijven, anders meet de telling enkel
        // speeldag 1 en beweert de test niets.
        $games = $this->confirm($second, app(DrawService::class)->draw($second)['games']);

        $this->assertCount(4, $games);
        $this->assertSame(1, $this->highestRepeat(), 'Geen enkel koppel hoort elkaar twee keer tegen te komen.');
    }

    public function test_wisselende_tegenstanders_vermijdt_de_scheefste_baan(): void
    {
        // Vier spelers met bonus 0 en vier met bonus 5. Een viertal van 2+2 geeft in
        // één van de drie sets 0 tegen 10 — de hoogste handicap die hier bestaat, en
        // net het soort set waarin het sterke duo op −5 begint. Elke andere verdeling
        // blijft op 5. De tie-break hoort dus nooit op 10 uit te komen.
        $this->makePlayers(8);
        $this->makeRecreants([5, 6, 7, 8]);

        // Twintig lotingen, want de eerste twee keuzes in een viertal zijn bij een
        // leeg geheugen willekeurig; enkel de vierde keuze weegt de handicap.
        foreach (range(1, 20) as $number) {
            $round = $this->roundWithPresentPlayers($number, range(1, 8));

            foreach (app(DrawService::class)->draw($round)['games'] as $game) {
                $this->assertLessThanOrEqual(
                    5,
                    $this->highestHandicap($game),
                    'Een baan van twee sterke tegen twee zwakke spelers hoort de tie-break te vermijden.'
                );
            }
        }
    }

    public function test_wisselende_tegenstanders_geeft_meer_verschillende_tegenstanders_dan_sterktegroepen(): void
    {
        $this->makePlayers(40);

        $varying = $this->playSeason(DrawSystem::VaryingOpponents);
        $strength = $this->playSeason(DrawSystem::StrengthGroups);

        $this->assertGreaterThan(
            $strength['averageOpponents'],
            $varying['averageOpponents'],
            'Het nieuwe systeem hoort op dezelfde poel strikt meer verschillende tegenstanders te geven.'
        );

        // Gemeten tegen het plafond in plaats van tegen een verzonnen getal: wie zes
        // wedstrijden speelt kan hoogstens achttien verschillende mensen tegenkomen.
        // Een werkend geheugen komt daar binnen 10 % van (gemeten 97 %); zonder
        // geheugen blijft het rond 80 %, en sterktegroepen rond 75 %. Dít is de
        // assertie die valt als het geheugen stopt te werken — de vergelijking
        // hierboven blijft dan groen, want willekeurig loten verslaat sterktegroepen
        // ook zonder geheugen.
        $this->assertGreaterThan(
            0.9 * $varying['ceiling'],
            $varying['averageOpponents'],
            'Het geheugen hoort binnen 10% van het haalbare maximum te komen.'
        );
    }

    public function test_wisselende_tegenstanders_begint_bij_wie_het_moeilijkst_te_plaatsen_is(): void
    {
        // Speler 1 speelde al tegen zes van de zeven anderen, speler 8 tegen niemand.
        // Speler 1 is dus het moeilijkst met vreemden te omringen en hoort als eerste
        // een baan te krijgen, met speler 8 erbij — de enige die hij nog niet kende.
        //
        // Dat is geen kansuitspraak maar een gedwongen uitkomst: speler 1 is de unieke
        // hoogste in ontmoetingen en speler 8 de unieke laagste tegenover hem. Begint
        // de loting bij een willekeurige speler, dan schuift speler 1 door naar de baan
        // die overblijft en valt dit om.
        $this->makePlayers(8);

        $first = $this->roundWithPresentPlayers(1, [1, 2, 3, 4]);
        $first->games()->create($this->gamePlayers([1, 2, 3, 4]));

        $second = $this->roundWithPresentPlayers(2, [1, 5, 6, 7]);
        $second->games()->create($this->gamePlayers([1, 5, 6, 7]));

        // Tien keer, want de rest van het viertal blijft willekeurig; enkel het paar
        // 1-8 staat vast. `draw()` bewaart niets, dus het geheugen blijft ondertussen
        // precies deze twee wedstrijden.
        $round = $this->roundWithPresentPlayers(3, range(1, 8));

        foreach (range(1, 10) as $ignored) {
            $games = app(DrawService::class)->draw($round)['games'];

            $withPlayerOne = collect($games)->first(
                fn (array $game): bool => in_array($this->players[1]->id, $game, true)
            );

            $this->assertContains(
                $this->players[8]->id,
                $withPlayerOne,
                'Wie het meest gespeeld heeft, hoort als eerste geplaatst te worden en de meest onbekende tegenstander te krijgen.'
            );
        }
    }

    /**
     * Speel een heel seizoen onder één systeem en geef de spreidingscijfers terug.
     *
     * @return array{players: int, averageOpponents: float, highestRepeat: int}
     */
    private function playSeason(DrawSystem $system): array
    {
        $this->season->rounds()->each(fn (Round $round) => $round->delete());
        $this->season->update(['draw_system' => $system]);

        foreach (range(1, 6) as $number) {
            $round = $this->roundWithPresentPlayers($number, range(1, 40));

            $this->confirm($round, app(DrawService::class)->draw($round)['games']);
        }

        return $this->spreadFromGames();
    }

    /**
     * Tel de ontmoetingen rechtstreeks uit de `games`-rijen, los van
     * {@see SeasonEncounters}. De service die de loting voedt mag niet ook het
     * meetinstrument van haar eigen test zijn: breekt ze, dan hoort deze test een
     * herhaling te zien en niet een lege telling.
     *
     * `ceiling` is wat er hoogstens te halen valt: drie tegenstanders per gespeelde
     * wedstrijd. Zonder dat getal is elke uitspraak over "genoeg spreiding" een
     * verzonnen drempel.
     *
     * @return array{averageOpponents: float, highestRepeat: int, ceiling: float}
     */
    private function spreadFromGames(): array
    {
        $met = [];
        $gamesPlayed = [];

        foreach (Game::whereIn('round_id', $this->season->rounds()->pluck('id'))->get() as $game) {
            foreach ($game->playerIds() as $player) {
                $gamesPlayed[$player] = ($gamesPlayed[$player] ?? 0) + 1;

                foreach ($game->playerIds() as $other) {
                    if ($player !== $other) {
                        $met[$player][$other] = ($met[$player][$other] ?? 0) + 1;
                    }
                }
            }
        }

        $distinct = 0;
        $highest = 0;
        foreach ($met as $opponents) {
            $distinct += count($opponents);
            $highest = max($highest, ...array_values($opponents));
        }

        if ($met === []) {
            return ['averageOpponents' => 0.0, 'highestRepeat' => 0, 'ceiling' => 0.0];
        }

        return [
            'averageOpponents' => round($distinct / count($met), 2),
            'highestRepeat' => $highest,
            'ceiling' => round(3 * array_sum($gamesPlayed) / count($gamesPlayed), 2),
        ];
    }

    /**
     * Bevestig gelote viertallen, zoals de zaal-app doet: de loting stelt voor, pas
     * `storeGame` schrijft weg.
     *
     * @param  list<list<int>>  $games
     * @return list<list<int>>
     */
    private function confirm(Round $round, array $games): array
    {
        foreach ($games as $playerIds) {
            $round->games()->create([
                'player1_id' => $playerIds[0],
                'player2_id' => $playerIds[1],
                'player3_id' => $playerIds[2],
                'player4_id' => $playerIds[3],
            ]);
        }

        return $games;
    }

    private function makePlayers(int $count): void
    {
        foreach (range(1, $count) as $index) {
            $this->players[$index] = Player::create([
                'first_name' => sprintf('Speler%02d', $index),
                'last_name' => 'Test',
                'gender' => 'male',
                'birth_date' => '1990-01-01',
                'double_ranking' => 0,
                'plays_competition' => true,
                'is_member' => true,
            ]);

            PlayerSeasonStatistic::create([
                'season_id' => $this->season->id,
                'player_id' => $this->players[$index]->id,
                'base_points' => $this->format->startingBasePoints() + ($count - $index) / 100,
            ]);
        }
    }

    /** Recreanten krijgen 5 bonuspunten; de rest staat op 0. */
    private function makeRecreants(array $indexes): void
    {
        foreach ($indexes as $index) {
            $this->players[$index]->update(['plays_competition' => false]);
        }
    }

    /** @param array<int, int> $strengthByIndex hoger = sterker */
    private function setStrength(array $strengthByIndex): void
    {
        foreach ($strengthByIndex as $index => $strength) {
            PlayerSeasonStatistic::where('season_id', $this->season->id)
                ->where('player_id', $this->players[$index]->id)
                ->update(['base_points' => $this->format->startingBasePoints() + $strength / 100]);
        }
    }

    /** @param array<int, int> $playerIndexes */
    private function roundWithPresentPlayers(int $number, array $playerIndexes): Round
    {
        $round = $this->season->rounds()->create([
            'number' => $number,
            'date' => sprintf('2026-09-%02d', min($number, 28)),
        ]);

        foreach ($playerIndexes as $index) {
            PlayerRoundStatistic::updateOrCreate(
                ['round_id' => $round->id, 'player_id' => $this->players[$index]->id],
                ['is_present' => true],
            );
        }

        return $round;
    }

    /** @param array<int, int> $playerIndexes */
    private function gamePlayers(array $playerIndexes): array
    {
        return [
            'player1_id' => $this->players[$playerIndexes[0]]->id,
            'player2_id' => $this->players[$playerIndexes[1]]->id,
            'player3_id' => $this->players[$playerIndexes[2]]->id,
            'player4_id' => $this->players[$playerIndexes[3]]->id,
        ];
    }

    /** @param array<int, int> $playerIndexes */
    private function ids(array $playerIndexes): array
    {
        return array_map(fn (int $index): int => $this->players[$index]->id, $playerIndexes);
    }

    private function highestRepeat(): int
    {
        return $this->spreadFromGames()['highestRepeat'];
    }

    /** @param list<int> $playerIds */
    private function highestHandicap(array $playerIds): int
    {
        $bonus = array_map(fn (int $id): int => Player::find($id)->bonus_points, $playerIds);

        $highest = 0;
        foreach (Game::LINE_UPS as [$homeSlots, $awaySlots]) {
            $home = $bonus[$homeSlots[0] - 1] + $bonus[$homeSlots[1] - 1];
            $away = $bonus[$awaySlots[0] - 1] + $bonus[$awaySlots[1] - 1];
            $highest = max($highest, abs($home - $away));
        }

        return $highest;
    }
}
