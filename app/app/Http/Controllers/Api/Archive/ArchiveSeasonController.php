<?php

namespace App\Http\Controllers\Api\Archive;

use App\Http\Controllers\Controller;
use App\Http\Resources\Archive\ArchiveSeasonResource;
use App\Models\Archive\ArchiveSeason;
use App\Services\Legacy\ArchiveStandings;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Gearchiveerde seizoenen (2009-2023), alleen-lezen.
 *
 * Van een afgesloten seizoen blijft publiek enkel de eindstand, en het archief
 * bestaat uitsluitend uit afgesloten seizoenen. Er zijn dus twee endpoints: de
 * index om de veertien standen te vinden, en de stand zelf. De speeldagen en
 * uitslagen die hier vroeger onder /rounds stonden, zijn verwijderd — die blijven
 * wel volledig zichtbaar in het beheerspaneel.
 */
class ArchiveSeasonController extends Controller
{
    public function __construct(private readonly ArchiveStandings $standings) {}

    public function index(): AnonymousResourceCollection
    {
        $seasons = ArchiveSeason::query()->withCount('rounds')->orderBy('name')->get();

        // `players_count` is de lengte van de eindstand en niet het aantal
        // seizoensrijen: dit getal komt op de site naast een link naar die stand te
        // staan. Zou het de inschrijvingen tellen, dan stond er "142 spelers" boven
        // een tabel van 81 rijen zonder dat iets waarschuwde. Dat kost twee queries
        // per seizoen — veertien seizoenen, build-time opgehaald, dus dat mag.
        foreach ($seasons as $season) {
            $season->players_count = $this->standings->countForSeason($season);
        }

        return ArchiveSeasonResource::collection($seasons);
    }

    /**
     * De eindstand van het seizoen. Alle veertien seizoenen hebben er een, ook
     * 2010-2011: dat werd destijds nooit correct gearchiveerd en wordt bij de
     * import herberekend uit de uitslagen.
     *
     * @return array<string, mixed>
     */
    public function standings(ArchiveSeason $season): array
    {
        $laatsteSpeeldag = $this->standings->laatsteSpeeldag($season);

        return [
            'data' => $this->standings->forSeason($season)->map(fn (object $rij): array => [
                'archive_player_id' => (int) $rij->archive_player_id,
                'player_id' => $rij->player_id === null ? null : (int) $rij->player_id,
                'first_name' => $rij->first_name,
                'last_name' => $rij->last_name,
                'full_name' => trim("{$rij->first_name} {$rij->last_name}"),
                // Staat erbij omdat de site hier het damesklassement van een oude
                // jaargang uit afleidt; voor de huidige seizoenen doet
                // /rankings?category=women dat, en die route bestaat niet voor het
                // archief. Zelfde waarden als /players ('male'/'female'), zodat de
                // site één filter heeft en geen tweede vocabulaire.
                //
                // Null enkel voor de acht "Onbekende speler"-rijen: dat zijn
                // comp-spelers die uit hun eigen ledentabel verwijderd waren, maar
                // van wie de uitslagen wél echt gespeeld zijn. Alle 192 herkende
                // archiefspelers hebben een geslacht.
                'gender' => $rij->gender,
                'ranking' => $rij->ranking,
                // Op twee cijfers zoals elk ander gemiddelde in deze API. De
                // sortering gebeurt in SQL op de ruwe waarde, dus dit raakt enkel
                // wat er getoond wordt.
                'average' => round((float) $rij->average, 2),
                'base_points' => $rij->base_points === null ? null : (float) $rij->base_points,
                'sets' => [
                    'won' => (int) $rij->sets_won,
                    'total' => (int) $rij->sets_played,
                ],
                'points' => [
                    'won' => (int) $rij->points_won,
                    'total' => (int) $rij->points_played,
                ],
                'games' => [
                    'won' => (int) $rij->games_won,
                    'total' => (int) $rij->games_played,
                ],
                'rounds' => [
                    'present' => (int) $rij->rounds_present,
                ],
            ])->values()->all(),
            'meta' => [
                'season' => ['id' => $season->id, 'name' => $season->name, 'source' => $season->source],
                'after_round' => $laatsteSpeeldag === null ? null : [
                    'id' => $laatsteSpeeldag->id,
                    'number' => $laatsteSpeeldag->number,
                    'date' => $laatsteSpeeldag->date->format('Y-m-d'),
                ],
            ],
        ];
    }
}
