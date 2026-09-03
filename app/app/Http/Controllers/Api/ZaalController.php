<?php

namespace App\Http\Controllers\Api;

use App\Enums\Gender;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\Player;
use App\Models\PlayerRoundStatistic;
use App\Models\PlayerSeasonStatistic;
use App\Models\Round;
use App\Models\Season;
use App\Services\DrawService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Endpoints voor de zaal-app: aanwezigheden, loting en score-invoer op de
 * speeldag zelf. Alles achter authenticatie.
 *
 * Een aangemaakte match kan hier niet verwijderd worden: eenmaal aangemaakt
 * blijft ze bestaan. Corrigeren gebeurt in het beheerspaneel.
 */
class ZaalController extends Controller
{
    public function __construct(private readonly DrawService $drawService) {}

    /**
     * De speeldag waaraan in de zaal gewerkt wordt: die van vandaag.
     *
     * Bewust niet "de laatste speeldag": staat die van vandaag er nog niet, dan
     * zouden aanwezigheden en matches op een al afgesloten speeldag belanden.
     * In dat geval krijgt de zaal een lege toestand met de keuze om de speeldag
     * van vandaag te starten of bewust een oudere te openen.
     */
    public function currentRound(): JsonResponse
    {
        $season = Season::current();
        $today = $season?->rounds()->whereDate('date', today())->first();

        if ($today !== null) {
            return response()->json($this->roundPayload($today));
        }

        $latest = $season?->rounds()->orderByDesc('date')->orderByDesc('id')->first();

        return response()->json([
            'round' => null,
            'players' => [],
            'presentCount' => 0,
            'drawnOut' => [],
            'games' => [],
            'seasonName' => $season?->name,
            'pointsPerSet' => $season?->points_per_set->value,
            'maxScore' => $season?->points_per_set->cap(),
            'latestRound' => $latest === null ? null : [
                'id' => $latest->id,
                'number' => $latest->number,
                'date' => $latest->date->format('Y-m-d'),
            ],
        ]);
    }

    /** Start de speeldag van vandaag; bestaat die al, dan wordt die geopend. */
    public function storeRound(): JsonResponse
    {
        $season = Season::current();

        abort_if($season === null, 422, 'Er is nog geen seizoen aangemaakt.');

        $round = $season->rounds()->whereDate('date', today())->first()
            ?? $season->rounds()->create([
                'date' => today()->toDateString(),
                'number' => (int) $season->rounds()->max('number') + 1,
            ]);

        return response()->json($this->roundPayload($round), 201);
    }

    public function show(Round $round): JsonResponse
    {
        return response()->json($this->roundPayload($round));
    }

    /** Zet een speler aanwezig of afwezig. */
    public function setAttendance(Request $request, Round $round): JsonResponse
    {
        $data = $request->validate([
            'playerId' => ['required', 'integer', Rule::exists('players', 'id')],
            'present' => ['required', 'boolean'],
        ]);

        PlayerRoundStatistic::updateOrCreate(
            ['round_id' => $round->id, 'player_id' => $data['playerId']],
            ['is_present' => $data['present']] + ($data['present'] ? [] : ['is_drawn_out' => false]),
        );

        return response()->json($this->roundPayload($round));
    }

    /** Loot de speeldag; bewaart meteen wie uitgeloot is. */
    public function draw(Round $round): JsonResponse
    {
        $result = $this->drawService->draw($round);

        return response()->json([
            'proposedGames' => array_map(
                fn (array $playerIds): array => array_map(
                    fn (int $id): array => $this->playerSummary(Player::find($id)),
                    $playerIds
                ),
                $result['games'],
            ),
        ] + $this->roundPayload($round));
    }

    /**
     * Maak een match aan (zonder scores). Wordt gebruikt om een geloot viertal te
     * bevestigen, om uitgelote spelers aan te vullen met vrijwilligers, en om een
     * vrije match samen te stellen. Spelers die nog niet aanwezig stonden — een
     * laatkomer die meteen invalt — worden meteen aanwezig gezet.
     */
    public function storeGame(Request $request, Round $round): JsonResponse
    {
        $data = $request->validate([
            'playerIds' => ['required', 'array', 'size:4'],
            'playerIds.*' => ['required', 'integer', 'distinct', Rule::exists('players', 'id')],
        ]);

        DB::transaction(function () use ($data, $round): void {
            foreach ($data['playerIds'] as $playerId) {
                PlayerRoundStatistic::updateOrCreate(
                    ['round_id' => $round->id, 'player_id' => $playerId],
                    ['is_present' => true],
                );
            }

            $round->games()->create([
                'player1_id' => $data['playerIds'][0],
                'player2_id' => $data['playerIds'][1],
                'player3_id' => $data['playerIds'][2],
                'player4_id' => $data['playerIds'][3],
            ]);
        });

        return response()->json($this->roundPayload($round));
    }

    /**
     * Sla de setstanden van een game op.
     *
     * Eerst elk getal apart (een 47 is geen setstand, en dan hoort de melding bij
     * dat vakje te staan), pas daarna de twee getallen samen tegen de regel van
     * het seizoen. Sets die nog leeg zijn blijven leeg; half ingevuld kan niet.
     */
    public function updateGame(Request $request, Game $game): JsonResponse
    {
        $pointsPerSet = $game->round->season->points_per_set;
        $cap = $pointsPerSet->cap();

        $rules = ['nullable', 'integer', 'min:0', "max:{$cap}"];
        $data = $request->validate(
            [
                'set1_home' => $rules, 'set1_away' => $rules,
                'set2_home' => $rules, 'set2_away' => $rules,
                'set3_home' => $rules, 'set3_away' => $rules,
            ],
            [
                'max' => "Meer dan {$cap} punten kan niet in een set.",
                'min' => 'Een setstand kan niet negatief zijn.',
                'integer' => 'Een setstand is een heel getal.',
            ],
        );

        $errors = [];
        foreach ([1, 2, 3] as $number) {
            $home = $data["set{$number}_home"] ?? null;
            $away = $data["set{$number}_away"] ?? null;

            if ($pointsPerSet->allowsSet($home, $away)) {
                continue;
            }

            // De melding hangt aan het thuisvak van de set: één plek, zodat de
            // zaal-app hem niet twee keer achter elkaar toont.
            $errors["set{$number}_home"] = ($home === null || $away === null)
                ? "Set {$number}: vul beide punten in of laat beide leeg."
                : "Set {$number}: {$home}-{$away} kan niet. {$pointsPerSet->setRule()}";
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $game->update($data);

        return response()->json($this->roundPayload($game->round));
    }

    /**
     * Wie kan er invallen om een onvolledig viertal aan te vullen? Invallen is
     * vrijwillig: de app kiest niemand, ze toont enkel wie in aanmerking komt.
     *
     * - "present": aanwezige leden die niet uitgeloot zijn, met hoeveel matches ze
     *   deze speeldag al speelden.
     * - "others": de overige leden, voor wie net binnenkomt; die wordt bij het
     *   aanmaken van de match meteen aanwezig gezet.
     */
    public function fillCandidates(Round $round): JsonResponse
    {
        $attendance = $round->playerStatistics()->get()->keyBy('player_id');

        $gamesPerPlayer = [];
        foreach ($round->games()->get(['player1_id', 'player2_id', 'player3_id', 'player4_id']) as $game) {
            foreach ($game->playerIds() as $playerId) {
                $gamesPerPlayer[$playerId] = ($gamesPerPlayer[$playerId] ?? 0) + 1;
            }
        }

        $members = Player::query()
            ->members()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(fn (Player $player): array => $this->playerSummary($player) + [
                'present' => (bool) ($attendance->get($player->id)?->is_present ?? false),
                'drawnOut' => (bool) ($attendance->get($player->id)?->is_drawn_out ?? false),
                'gamesPlayed' => $gamesPerPlayer[$player->id] ?? 0,
            ]);

        return response()->json([
            'drawnOut' => $members->where('drawnOut', true)->values(),
            'present' => $members->where('present', true)->where('drawnOut', false)->sortBy('gamesPlayed')->values(),
            'others' => $members->where('present', false)->values(),
        ]);
    }

    /**
     * Voeg tijdens de speeldag een nieuwe speler toe. Hij krijgt meteen
     * seizoensstatistieken (basispunten volgens de puntenschaal van het seizoen)
     * en staat aanwezig, zodat hij direct mee kan in de loting.
     */
    public function storePlayer(Request $request, Round $round): JsonResponse
    {
        $data = $request->validate([
            'firstName' => ['required', 'string', 'max:100'],
            'name' => ['required', 'string', 'max:100'],
            'gender' => ['required', Rule::in([Gender::Male->value, Gender::Female->value])],
            'birthDate' => ['required', 'date', 'before:today'],
            'playsCompetition' => ['required', 'boolean'],
            'doubleRanking' => ['required', 'integer', 'min:0', 'max:12'],
            'basePoints' => ['nullable', 'numeric', 'min:0'],
            'present' => ['nullable', 'boolean'],
        ]);

        DB::transaction(function () use ($data, $round): void {
            $player = Player::create([
                'first_name' => $data['firstName'],
                'last_name' => $data['name'],
                'gender' => $data['gender'],
                'birth_date' => $data['birthDate'],
                'double_ranking' => $data['playsCompetition'] ? $data['doubleRanking'] : 0,
                'plays_competition' => $data['playsCompetition'],
                'is_member' => true,
            ]);

            $season = $round->season;
            PlayerSeasonStatistic::updateOrCreate(
                ['season_id' => $round->season_id, 'player_id' => $player->id],
                ['base_points' => $data['basePoints'] ?? $season->points_per_set->startingBasePoints()],
            );

            PlayerRoundStatistic::updateOrCreate(
                ['round_id' => $round->id, 'player_id' => $player->id],
                ['is_present' => $data['present'] ?? true],
            );
        });

        return response()->json($this->roundPayload($round), 201);
    }

    /**
     * Volledige toestand van een speeldag: wie is er, wie is uitgeloot, welke games.
     *
     * @return array<string, mixed>
     */
    private function roundPayload(Round $round): array
    {
        $round->loadMissing('season');

        $attendance = $round->playerStatistics()->get()->keyBy('player_id');

        $players = Player::query()
            ->members()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->map(function (Player $player) use ($attendance): array {
                $statistic = $attendance->get($player->id);

                return $this->playerSummary($player) + [
                    'present' => (bool) ($statistic?->is_present ?? false),
                    'drawnOut' => (bool) ($statistic?->is_drawn_out ?? false),
                ];
            })
            ->values();

        $games = $round->games()
            ->with(['player1', 'player2', 'player3', 'player4'])
            ->orderBy('id')
            ->get()
            ->map(fn (Game $game): array => $this->gamePayload($game))
            ->values();

        return [
            'round' => [
                'id' => $round->id,
                'number' => $round->number,
                'date' => $round->date->format('Y-m-d'),
                'seasonId' => $round->season_id,
                'pointsPerSet' => $round->season->points_per_set->value,
                'maxScore' => $round->season->points_per_set->cap(),
                'isCalculated' => (bool) $round->is_calculated,
                'isToday' => $round->date->isToday(),
            ],
            'players' => $players,
            'presentCount' => $players->where('present', true)->count(),
            'drawnOut' => $players->where('drawnOut', true)->values(),
            'games' => $games,
        ];
    }

    /**
     * Eén game met per set de twee duo's en de ingevulde punten. Niet-ingevulde
     * sets blijven null, zodat de zaal-app "nog niet ingevuld" kan tonen en er set
     * per set bewaard kan worden.
     *
     * @return array<string, mixed>
     */
    private function gamePayload(Game $game): array
    {
        $players = [
            1 => $game->player1,
            2 => $game->player2,
            3 => $game->player3,
            4 => $game->player4,
        ];

        // De duo's roteren per set: 1+2 vs 3+4, dan 1+3 vs 2+4, dan 1+4 vs 2+3.
        $pairings = [
            1 => [[1, 2], [3, 4]],
            2 => [[1, 3], [2, 4]],
            3 => [[1, 4], [2, 3]],
        ];

        $sets = [];
        foreach ($pairings as $number => [$home, $away]) {
            $sets[] = [
                'number' => $number,
                'home' => [
                    'players' => array_map(fn (int $slot): array => $this->playerSummary($players[$slot]), $home),
                    'score' => $game->{"set{$number}_home"},
                    'field' => "set{$number}_home",
                ],
                'away' => [
                    'players' => array_map(fn (int $slot): array => $this->playerSummary($players[$slot]), $away),
                    'score' => $game->{"set{$number}_away"},
                    'field' => "set{$number}_away",
                ],
            ];
        }

        return [
            'id' => $game->id,
            'players' => array_map(fn (Player $player): array => $this->playerSummary($player), array_values($players)),
            'sets' => $sets,
            'isComplete' => $game->is_complete,
            // Wanneer de score bewaard werd. Null zolang er niets ingevuld is: dan
            // gaat updated_at over het aanmaken van de game, niet over een score.
            'savedAt' => $game->has_score ? $game->updated_at?->toIso8601String() : null,
        ];
    }

    /** @return array<string, mixed> */
    private function playerSummary(Player $player): array
    {
        return [
            'id' => $player->id,
            'firstName' => $player->first_name,
            'name' => $player->last_name,
            'fullName' => $player->full_name,
            'bonusPoints' => $player->bonus_points,
        ];
    }
}
