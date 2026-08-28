<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Concerns\ResolvesSeason;
use App\Http\Controllers\Controller;
use App\Models\Season;
use App\Services\Records;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * Clubrecords over de seizoenen in het huidige format.
 *
 * Let op de afwijking van de rest van de API: `?season=` weglaten betekent hier
 * *alle* seizoenen, niet het lopende. Een clubrecord over één seizoen is geen
 * clubrecord. Wil je er toch één, geef dan een id of `current`; `all` mag ook
 * expliciet.
 *
 * Queryparameters: season, limit.
 */
class RecordController extends Controller
{
    use ResolvesSeason;

    private const DEFAULT_LIMIT = 10;

    /** Bovengrens: dit endpoint rekent over alle wedstrijden, geen reden om er een lange lijst uit te trekken. */
    private const MAX_LIMIT = 50;

    public function __construct(private readonly Records $records) {}

    /** @return array<string, mixed> */
    public function index(Request $request): array
    {
        $seasons = $this->seasons($request);
        $limit = min(max($request->integer('limit') ?: self::DEFAULT_LIMIT, 1), self::MAX_LIMIT);

        return [
            'data' => $this->records->all($seasons->pluck('id')->all(), $limit),
            'meta' => [
                'seasons' => $seasons
                    ->map(fn (Season $season): array => ['id' => $season->id, 'name' => $season->name])
                    ->values()
                    ->all(),
                'limit' => $limit,
            ],
        ];
    }

    /** @return Collection<int, Season> */
    private function seasons(Request $request): Collection
    {
        $value = $request->query('season');

        if ($value === null || $value === '' || $value === 'all') {
            return Season::query()->orderBy('id')->get();
        }

        return collect([$this->seasonFromPath((string) $value)]);
    }
}
