<?php

namespace App\Http\Controllers\Api;

use App\Enums\Gender;
use App\Http\Controllers\Controller;
use App\Http\Resources\GameResource;
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

/**
 * Endpoints voor de zaal-app: aanwezigheden, loting en score-invoer op de
 * speeldag zelf. Alles achter authenticatie.
 *
 * Een aangemaakte match kan hier niet verwijderd worden: eenmaal aangemaakt
 * blijft ze bestaan. Corrigeren gebeurt in het beheerspaneel.
 */
class ZaalController extends Controller
{
    /** Startpunt voor een speler die in de loop van het seizoen bijkomt (zoals in de legacy-app). */
    private const DEFAULT_BASE_POINTS = 19.0;

    public function __construct(private readonly DrawService $drawService) {}

    /** De speeldag waar in de zaal aan gewerkt wordt: de laatste van het huidige seizoen. */
    public function currentRound(): JsonResponse
    {
        $round = Season::current()?->rounds()->orderByDesc('date')->orderByDesc('id')->first();

        if ($round === null) {
            return response()->json(['round' => null, 'players' => [], 'games' => [], 'drawnOut' => []]);
        }

        return response()->json($this->roundPayload($round));
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

    /** Bevestig een match: maakt de game aan (zonder scores). */
    public function storeGame(Request $request, Round $round): JsonResponse
    {
        $data = $request->validate([
            'playerIds' => ['required', 'array', 'size:4'],
            'playerIds.*' => ['required', 'integer', 'distinct', Rule::exists('players', 'id')],
        ]);

        $round->games()->create([
            'player1_id' => $data['playerIds'][0],
            'player2_id' => $data['playerIds'][1],
            'player3_id' => $data['playerIds'][2],
            'player4_id' => $data['playerIds'][3],
        ]);

        return response()->json($this->roundPayload($round));
    }

    /** Sla de setstanden van een game op. */
    public function updateGame(Request $request, Game $game): JsonResponse
    {
        $rules = ['nullable', 'integer', 'min:0', 'max:50'];
        $data = $request->validate([
            'set1_home' => $rules, 'set1_away' => $rules,
            'set2_home' => $rules, 'set2_away' => $rules,
            'set3_home' => $rules, 'set3_away' => $rules,
        ]);

        $game->update($data);

        return response()->json($this->roundPayload($game->round));
    }

    /**
     * Voeg tijdens de speeldag een nieuwe speler toe. Hij krijgt meteen
     * seizoensstatistieken (basispunten zoals de legacy-app: 19) en staat aanwezig,
     * zodat hij direct mee kan in de loting.
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

            PlayerSeasonStatistic::updateOrCreate(
                ['season_id' => $round->season_id, 'player_id' => $player->id],
                ['base_points' => $data['basePoints'] ?? self::DEFAULT_BASE_POINTS],
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
            ->with(['round', 'player1', 'player2', 'player3', 'player4'])
            ->orderBy('id')
            ->get();

        return [
            'round' => [
                'id' => $round->id,
                'number' => $round->number,
                'date' => $round->date->format('Y-m-d'),
                'seasonId' => $round->season_id,
                'isCalculated' => (bool) $round->is_calculated,
            ],
            'players' => $players,
            'presentCount' => $players->where('present', true)->count(),
            'drawnOut' => $players->where('drawnOut', true)->values(),
            'games' => GameResource::collection($games)->resolve(),
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
        ];
    }
}
