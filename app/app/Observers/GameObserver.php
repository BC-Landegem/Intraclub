<?php

namespace App\Observers;

use App\Models\Game;
use App\Models\Round;
use App\Services\SeasonCalculator;

/**
 * Houdt de tussenstand gelijk met de ingevulde scores. Vervangt de handmatige
 * "bereken tussenstand"-actie uit de legacy-API.
 *
 * De SeasonCalculator rekent altijd het hele seizoen door, dus we roepen hem
 * enkel wanneer de uitkomst er ook echt van verandert. Dat is zo wanneer de
 * speeldag in de stand zit (is_calculated), of wanneer ze er door deze wijziging
 * in komt (alle games volledig ingevuld). Blijft ze onvolledig -- de hele avond
 * lang, tot de laatste set van de laatste match -- dan telt ze noch voor noch na
 * de wijziging mee en zou een herberekening precies dezelfde cijfers van de
 * vorige speeldagen terugschrijven. Een avond kost zo één berekening in plaats
 * van één per ingevulde set.
 */
class GameObserver
{
    private const SCORE_COLUMNS = [
        'set1_home', 'set1_away',
        'set2_home', 'set2_away',
        'set3_home', 'set3_away',
    ];

    public function __construct(private readonly SeasonCalculator $calculator) {}

    public function saved(Game $game): void
    {
        $this->clearDrawnOutForParticipants($game);

        if ($game->wasRecentlyCreated || $game->wasChanged(self::SCORE_COLUMNS)) {
            $this->recalculate($game->round);
        }
    }

    public function deleted(Game $game): void
    {
        $this->recalculate($game->round);
    }

    private function recalculate(Round $round): void
    {
        if (! $this->countsInTheStandings($round)) {
            return;
        }

        $this->calculator->calculate($round->season);
    }

    /**
     * Zit deze speeldag in de stand, voor of na de wijziging?
     *
     * De vlag komt vers uit de databank: een eerdere save in hetzelfde request
     * kan hem al verzet hebben, en dan is de speeldag in het geheugen achterhaald.
     */
    private function countsInTheStandings(Round $round): bool
    {
        if ((bool) Round::whereKey($round->getKey())->value('is_calculated')) {
            return true;
        }

        $games = $round->games()->get();

        return $games->isNotEmpty() && $games->every(fn (Game $game): bool => $game->is_complete);
    }

    /**
     * Wie in een game staat, speelt dus mee en is niet (langer) uitgeloot. Dit dekt
     * de situatie waarin een laatkomer de onvolledige match aanvult: de eerder
     * uitgelote spelers spelen dan toch en die speeldag telt weer voor hen mee.
     */
    private function clearDrawnOutForParticipants(Game $game): void
    {
        $game->round->playerStatistics()
            ->whereIn('player_id', $game->playerIds())
            ->where('is_drawn_out', true)
            ->update(['is_drawn_out' => false]);
    }
}
