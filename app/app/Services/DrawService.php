<?php

namespace App\Services;

use App\Enums\DrawSystem;
use App\Models\Game;
use App\Models\Player;
use App\Models\PlayerRoundStatistic;
use App\Models\Round;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Loting van een speeldag: verdeelt de aanwezige spelers over games van vier.
 *
 * Er zijn twee samenstellingsregels (`DrawSystem`, gekozen per seizoen). Alles
 * eromheen is gedeeld — wie meedoet, wie aan de kant blijft, het beschermingsvenster
 * en het bewaren van `is_drawn_out`. Enkel `composeGames()` splitst, zodat een
 * vergelijking tussen de twee systemen op één verschil berust.
 *
 * Gedeelde regels:
 * - Wie uitgeloot werd, is de volgende PROTECTED_ROUNDS speeldagen beschermd en
 *   blijft dus niet opnieuw aan de kant. Dat venster loopt door over een wissel van
 *   lotingsysteem midden in het seizoen heen.
 * - Wie overblijft (1-3 spelers) wordt als uitgeloot bewaard in
 *   player_round_statistics: het overleeft een refresh, weegt mee in volgende
 *   lotingen, en de tussenstand rekent die speeldag voor hem niet mee.
 * - Spelers die al een match hebben, doen niet meer mee aan een nieuwe loting.
 *   Zo deelt een tweede loting (bv. na laatkomers) enkel de rest in.
 * - De loting vult een onvolledig viertal nooit zelf aan met spelers die al
 *   speelden: invallen is vrijwillig en gebeurt via het aanvul-scherm in de zaal.
 */
class DrawService
{
    private const PLAYERS_PER_GAME = 4;

    /** Aandeel van de deelnemers per sterktegroep (legacy: 60% met 20% overlap). */
    private const GROUP_FRACTION = 0.6;

    /** Aantal speeldagen dat een speler na een uitloting beschermd is. */
    public const PROTECTED_ROUNDS = 5;

    public function __construct(private readonly SeasonEncounters $seasonEncounters) {}

    /**
     * Loot de speeldag en bewaar wie uitgeloot is.
     *
     * @return array{games: list<list<int>>, drawnOut: list<int>}
     */
    public function draw(Round $round): array
    {
        $result = $this->composeGames($this->participants($round), $round);

        $this->persistDrawnOut($round, $result['drawnOut']);

        return $result;
    }

    /**
     * Aanwezige leden die nog geen match hebben, gesorteerd op sterkte. Per speler
     * houden we bij hoeveel speeldagen geleden hij uitgeloot werd, en zijn
     * bonuspunten voor de handicap-tie-break van de tweede samensteller.
     *
     * @return Collection<int, array{id: int, average: float, bonus: int, roundsSinceDrawnOut: int|null}>
     */
    private function participants(Round $round): Collection
    {
        $lastDrawnOut = $this->lastDrawnOutRoundNumbers($round);
        $averages = $this->currentAverages($round);
        $alreadyPlaying = $this->playersWithGame($round);

        return $round->playerStatistics()
            ->where('is_present', true)
            ->with('player')
            ->get()
            ->filter(fn (PlayerRoundStatistic $statistic): bool => ($statistic->player?->is_member ?? false)
                && ! in_array($statistic->player_id, $alreadyPlaying, true))
            ->map(function (PlayerRoundStatistic $statistic) use ($averages, $lastDrawnOut, $round): array {
                $lastNumber = $lastDrawnOut[$statistic->player_id] ?? null;

                return [
                    'id' => $statistic->player_id,
                    'average' => $averages[$statistic->player_id] ?? 0.0,
                    'bonus' => $statistic->player?->bonus_points ?? 0,
                    'roundsSinceDrawnOut' => $lastNumber === null ? null : $round->number - $lastNumber,
                ];
            })
            ->sortByDesc('average')
            ->values();
    }

    /**
     * Spelers die deze speeldag al in een match staan.
     *
     * @return list<int>
     */
    private function playersWithGame(Round $round): array
    {
        return Game::playerIdsInRounds([$round->id])->all();
    }

    /**
     * Het nummer van de laatste speeldag waarop elke speler uitgeloot werd, binnen
     * dit seizoen en vóór de huidige speeldag.
     *
     * @return array<int, int>
     */
    private function lastDrawnOutRoundNumbers(Round $round): array
    {
        return DB::table('player_round_statistics as statistic')
            ->join('rounds', 'rounds.id', '=', 'statistic.round_id')
            ->where('rounds.season_id', $round->season_id)
            ->where('rounds.number', '<', $round->number)
            ->where('statistic.is_drawn_out', true)
            ->groupBy('statistic.player_id')
            ->selectRaw('statistic.player_id, MAX(rounds.number) as laatste')
            ->pluck('laatste', 'player_id')
            ->map(fn ($number): int => (int) $number)
            ->all();
    }

    /**
     * Laatst berekende gemiddelde per speler; valt terug op de basispunten wanneer
     * er nog geen speeldag berekend is. Bepaalt de sterktegroep.
     *
     * @return array<int, float>
     */
    private function currentAverages(Round $round): array
    {
        $averages = DB::table('player_season_statistics')
            ->where('season_id', $round->season_id)
            ->pluck('base_points', 'player_id')
            ->map(fn ($points): float => (float) $points)
            ->all();

        $calculated = DB::table('player_round_statistics as statistic')
            ->join('rounds', 'rounds.id', '=', 'statistic.round_id')
            ->where('rounds.season_id', $round->season_id)
            ->whereNotNull('statistic.average')
            ->orderBy('rounds.number')
            ->get(['statistic.player_id', 'statistic.average']);

        foreach ($calculated as $row) {
            $averages[$row->player_id] = (float) $row->average;
        }

        return $averages;
    }

    /**
     * Bepaal wie aan de kant blijft en stel met de rest de viertallen samen. Enkel de
     * samenstelling verschilt per seizoen; de uitloting is voor beide dezelfde.
     *
     * @param  Collection<int, array{id: int, average: float, bonus: int, roundsSinceDrawnOut: int|null}>  $participants
     * @return array{games: list<list<int>>, drawnOut: list<int>}
     */
    private function composeGames(Collection $participants, Round $round): array
    {
        $count = $participants->count();
        if ($count < self::PLAYERS_PER_GAME) {
            return ['games' => [], 'drawnOut' => $participants->pluck('id')->all()];
        }

        // Eerst bepalen wie aan de kant blijft: enkel de rest na deling door vier.
        // Daardoor is het aantal spelers een veelvoud van vier en houdt geen van de
        // twee samenstellers iemand over — enkel `selectSittingOut` loot uit.
        [$playing, $drawnOut] = $this->selectSittingOut($participants, $count % self::PLAYERS_PER_GAME);

        return [
            'games' => $round->season->draw_system === DrawSystem::VaryingOpponents
                ? $this->byVaryingOpponents($playing, $round)
                : $this->byStrengthGroups($playing),
            'drawnOut' => $drawnOut->pluck('id')->all(),
        ];
    }

    /**
     * Twee overlappende sterktegroepen, beurtelings sterk en zwak, willekeurig binnen
     * de groep — de legacy-loting.
     *
     * @param  Collection<int, array{id: int, average: float, bonus: int, roundsSinceDrawnOut: int|null}>  $playing
     * @return list<list<int>>
     */
    private function byStrengthGroups(Collection $playing): array
    {
        $playingCount = $playing->count();
        $groups = [
            $playing->take((int) floor($playingCount * self::GROUP_FRACTION))->all(),
            $playing->slice((int) floor($playingCount * (1 - self::GROUP_FRACTION)))->all(),
        ];

        $games = [];
        $used = [];

        do {
            $drewThisPass = false;
            foreach ($groups as $group) {
                $available = array_values(array_filter($group, fn (array $player): bool => ! isset($used[$player['id']])));
                if (count($available) < self::PLAYERS_PER_GAME) {
                    continue;
                }
                $picked = $this->pickFour($available);
                foreach ($picked as $playerId) {
                    $used[$playerId] = true;
                }
                $games[] = $picked;
                $drewThisPass = true;
            }
        } while ($drewThisPass);

        // Wie door de groepsindeling overblijft, vormt de laatste games. Dat zijn de
        // spelers in de overlap die geen van beide groepen nog kon vullen.
        $remaining = $playing->reject(fn (array $player): bool => isset($used[$player['id']]))->values();
        while ($remaining->count() >= self::PLAYERS_PER_GAME) {
            $picked = $this->pickFour($remaining->all());
            $games[] = $picked;
            $remaining = $remaining->reject(fn (array $player): bool => in_array($player['id'], $picked, true))->values();
        }

        return $games;
    }

    /**
     * Deel in op wie dit seizoen nog het minst tegen elkaar speelde. Sterkte speelt
     * geen rol: gemeten op drie seizoenen veroorzaken de sterktegroepen de herhaling
     * niet (willekeurig loten geeft evenveel verschillende tegenstanders als de
     * huidige loting), ze kosten alleen kandidaten om uit te kiezen.
     *
     * Bij gelijke stand — vroeg in het seizoen bijna altijd — kiest de tie-break de
     * kandidaat die de *hoogste* handicap van het viertal het laagst houdt. Niet de
     * som: die verlaagt het gemiddelde maar duwt de scheefheid naar het laatste
     * viertal, dat de restjes krijgt, en verdubbelt zo net de uitschieters waar de
     * score-invoer op stukloopt.
     *
     * @param  Collection<int, array{id: int, average: float, bonus: int, roundsSinceDrawnOut: int|null}>  $playing
     * @return list<list<int>>
     */
    private function byVaryingOpponents(Collection $playing, Round $round): array
    {
        // Het geheugen is een pure functie van de `games`-rijen, dus na een loting valt
        // er niets bij te werken: de wedstrijden van vanavond staan er zodra de zaal ze
        // bevestigt, en de volgende speeldag leest ze gewoon mee.
        //
        // Binnen deze ene loting valt er niets te onthouden. Elke speler komt precies
        // één keer in `$playing` (unieke index op round_id + player_id) en verlaat de
        // lijst zodra hij een baan heeft, dus een net gevormd viertal kan onmogelijk
        // nog een kandidaat raken. Hier stond een `remember()` die dat wel probeerde;
        // over twaalf herlotingen van drie seizoenen werd geen enkel zo bijgehouden
        // paar ooit opgevraagd.
        $encounters = $this->seasonEncounters->forSeason($round->season_id);
        $left = $playing->shuffle()->all();
        $games = [];

        while (count($left) >= self::PLAYERS_PER_GAME) {
            $game = [array_splice($left, $this->hardestToPlace($left, $encounters), 1)[0]];

            while (count($game) < self::PLAYERS_PER_GAME) {
                $chosen = $this->leastMet($game, $left, $encounters);
                $game[] = $left[$chosen];
                array_splice($left, $chosen, 1);
            }

            $games[] = array_column($game, 'id');
        }

        return $games;
    }

    /**
     * Met wie beginnen we het volgende viertal? Met de speler die de meeste
     * ontmoetingen heeft met wie er nog te plaatsen is, want hij is het moeilijkst te
     * omringen met vreemden.
     *
     * Zonder deze keuze begint elk viertal met een willekeurige speler en krijgt de
     * laatste baan van de avond de restjes — precies de plek waar de resterende
     * herhalingen vandaan komen. "Meest beperkte eerst" is dezelfde heuristiek als bij
     * de handicap-tie-break: het uiterste geval aanpakken, niet het gemiddelde.
     *
     * Op 2023-2024, het krapste van de drie seizoenen, over twaalf herlotingen: de
     * hoogste herhaling zakt van 3,1 (uitschieter 4×) naar 2,2, de koppels die elkaar
     * drie keer zien van 0,35 % naar 0,02 %, en de spreiding stijgt van 92 % naar 95 %
     * van het plafond. Op de andere twee seizoenen blijft de hoogste herhaling 2.
     * De handicap blijft onaangeroerd; dit kost dus niets elders.
     *
     * @param  list<array{id: int, bonus: int}>  $left
     * @param  array<int, array<int, int>>  $encounters
     */
    private function hardestToPlace(array $left, array $encounters): int
    {
        $hardest = 0;
        $highestMet = -1;

        foreach ($left as $index => $player) {
            $met = 0;
            foreach ($left as $other) {
                if ($other['id'] !== $player['id']) {
                    $met += $encounters[$player['id']][$other['id']] ?? 0;
                }
            }

            if ($met > $highestMet) {
                $hardest = $index;
                $highestMet = $met;
            }
        }

        return $hardest;
    }

    /**
     * De kandidaat die het minst tegen dit halve viertal speelde. Staat het viertal
     * op drie, dan beslist bij gelijke stand de laagste hoogste handicap.
     *
     * Gerangschikt op de *som* van de ontmoetingen, niet op de ergste ervan. Dat is
     * gemeten: rangschikken op het maximum eerst (met de som als tie-break) laat de
     * spreiding onveranderd, maar verdubbelt de handicapstaart — H≥10 gaat van 0,45 %
     * naar 1,21 % (2023-2024), 0,44 % naar 0,74 % en 0,36 % naar 0,52 %. Het maximum
     * is een grover criterium, dus het knipt kandidaten weg vóór de handicap-tie-break
     * er iets over te zeggen heeft. De herhaling waarvoor je dat zou doen is er al
     * bijna niet meer: met `hardestToPlace` blijft de hoogste herhaling op 2 tot 3,
     * en komt hoogstens 0,02 % van de koppels drie keer samen. Draai deze twee dus
     * niet om — meet het met `draw:replay` als je toch twijfelt.
     *
     * @param  list<array{id: int, bonus: int}>  $game
     * @param  list<array{id: int, bonus: int}>  $candidates
     * @param  array<int, array<int, int>>  $encounters
     */
    private function leastMet(array $game, array $candidates, array $encounters): int
    {
        $chosen = 0;
        $best = null;

        foreach ($candidates as $index => $candidate) {
            $met = [];
            foreach ($game as $member) {
                $met[] = $encounters[$member['id']][$candidate['id']] ?? 0;
            }

            // De handicap valt pas te berekenen zodra het viertal compleet zou zijn:
            // de duo's roteren, dus met drie spelers bestaan de sets nog niet.
            $handicap = count($game) === self::PLAYERS_PER_GAME - 1
                ? $this->highestHandicap([...$game, $candidate])
                : 0;

            $score = [array_sum($met), $handicap];

            if ($best === null || ($score <=> $best) < 0) {
                $chosen = $index;
                $best = $score;
            }
        }

        return $chosen;
    }

    /**
     * De grootste voorsprong die in dit viertal over de drie sets voorkomt. De
     * handicap van een set is het verschil tussen de bonussommen van beide duo's
     * (zie Handicap); welk duo tegen welk speelt volgt uit Game::LINE_UPS.
     *
     * @param  list<array{id: int, bonus: int}>  $game
     */
    private function highestHandicap(array $game): int
    {
        $highest = 0;

        foreach (Game::LINE_UPS as [$homeSlots, $awaySlots]) {
            $home = $game[$homeSlots[0] - 1]['bonus'] + $game[$homeSlots[1] - 1]['bonus'];
            $away = $game[$awaySlots[0] - 1]['bonus'] + $game[$awaySlots[1] - 1]['bonus'];

            $highest = max($highest, abs($home - $away));
        }

        return $highest;
    }

    /**
     * Verdeel de deelnemers in wie speelt en wie aan de kant blijft.
     *
     * Wie de voorbije PROTECTED_ROUNDS speeldagen uitgeloot werd, is beschermd en
     * komt pas aan de beurt als er te weinig onbeschermde spelers zijn; dan valt de
     * keuze op wie het langst geleden aan de kant stond. Binnen een gelijke groep
     * beslist het toeval.
     *
     * @param  Collection<int, array{id: int, average: float, bonus: int, roundsSinceDrawnOut: int|null}>  $participants
     * @return array{0: Collection<int, array<string, mixed>>, 1: Collection<int, array<string, mixed>>}
     */
    private function selectSittingOut(Collection $participants, int $sitOutCount): array
    {
        if ($sitOutCount === 0) {
            return [$participants, collect()];
        }

        $sittingOut = $participants
            ->shuffle()
            ->sortBy([
                // Onbeschermde spelers eerst; daarna wie het langst geleden aan de
                // kant stond (nooit uitgeloot telt als "oneindig lang geleden").
                fn (array $a, array $b): int => ($this->isProtected($a) ? 1 : 0) <=> ($this->isProtected($b) ? 1 : 0),
                fn (array $a, array $b): int => ($b['roundsSinceDrawnOut'] ?? PHP_INT_MAX) <=> ($a['roundsSinceDrawnOut'] ?? PHP_INT_MAX),
            ])
            ->take($sitOutCount)
            ->values();

        $sittingOutIds = $sittingOut->pluck('id')->all();

        return [
            $participants->reject(fn (array $player): bool => in_array($player['id'], $sittingOutIds, true))->values(),
            $sittingOut,
        ];
    }

    /** @param array{roundsSinceDrawnOut: int|null} $player */
    private function isProtected(array $player): bool
    {
        $since = $player['roundsSinceDrawnOut'];

        return $since !== null && $since <= self::PROTECTED_ROUNDS;
    }

    /**
     * Kies vier spelers uit een groep.
     *
     * @param  list<array{id: int, average: float, bonus: int, roundsSinceDrawnOut: int|null}>  $available
     * @return list<int>
     */
    private function pickFour(array $available): array
    {
        shuffle($available);

        return array_map(
            fn (array $player): int => $player['id'],
            array_slice($available, 0, self::PLAYERS_PER_GAME)
        );
    }

    /**
     * Bewaar wie uitgeloot is. Spelers die eerder uitgeloot waren maar nu wél
     * ingedeeld zijn, verliezen de vlag.
     *
     * @param  list<int>  $drawnOutPlayerIds
     */
    private function persistDrawnOut(Round $round, array $drawnOutPlayerIds): void
    {
        $round->playerStatistics()->where('is_drawn_out', true)->update(['is_drawn_out' => false]);

        if ($drawnOutPlayerIds !== []) {
            $round->playerStatistics()
                ->whereIn('player_id', $drawnOutPlayerIds)
                ->update(['is_drawn_out' => true]);
        }
    }

    /**
     * Spelers die deze speeldag uitgeloot zijn en dus niet mogen meedoen.
     *
     * @return Collection<int, Player>
     */
    public function drawnOutPlayers(Round $round): Collection
    {
        return Player::query()
            ->whereIn('id', $round->playerStatistics()->where('is_drawn_out', true)->pluck('player_id'))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get();
    }
}
