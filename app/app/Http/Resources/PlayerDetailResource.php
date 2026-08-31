<?php

namespace App\Http\Resources;

use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Speler met zijn tellers voor het lopende seizoen, zijn seizoenstabel van
 * vroeger, en op vraag zijn wedstrijden en rankingverloop.
 *
 * `statistics` gaat over het seizoen in `meta.season`; `seasons` is de
 * geschiedenis, met per afgesloten seizoen de vijf kolommen van de eindstand
 * (plaats, gemiddelde, sets, matchen, aanwezig). Die tabel is niet openklapbaar
 * naar speeldagen — van vóór het lopende seizoen is dit alles wat een fiche geeft.
 *
 * De sub-resources bestaan ook los (/players/{player}/games en
 * /players/{player}/ranking-history). `?include=` bestaat omdat de
 * spelerspagina alle drie tegelijk nodig heeft en dat geen drie requests hoeft
 * te zijn.
 *
 * @mixin Player
 */
class PlayerDetailResource extends JsonResource
{
    /**
     * @param  array<string, mixed>  $statistics
     * @param  list<array<string, mixed>>  $seasons
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        Player $player,
        private readonly array $statistics,
        private readonly array $seasons = [],
        private readonly array $extra = [],
    ) {
        parent::__construct($player);
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'full_name' => $this->full_name,
            'gender' => $this->gender->value,
            'double_ranking' => (int) $this->double_ranking,
            'plays_competition' => (bool) $this->plays_competition,
            'is_veteran' => $this->is_veteran,
            'is_recreant' => $this->is_recreant,
            'is_member' => (bool) $this->is_member,
            'bonus_points' => $this->bonus_points,
            'statistics' => $this->statistics,
            'seasons' => $this->seasons,
        ] + $this->extra;
    }
}
