<?php

namespace App\Observers;

use App\Enums\PointsPerSet;
use App\Exceptions\SeasonScaleIsFixed;
use App\Models\Season;

/**
 * De puntenschaal van een seizoen ligt vast zodra er een speeldag staat.
 *
 * Ze achteraf omzetten herinterpreteert immers de setstanden die er al zijn: een
 * set die tot 15 gespeeld werd, gaat dan als een set tot 21 door de berekening.
 * Dat is geen herberekening maar een vervalsing van wat er in de zaal gebeurd is,
 * en er is geen weg terug naar de juiste cijfers.
 *
 * Zolang het seizoen nog leeg is, mag het wél: dan verschuiven enkel de
 * basispunten mee naar de nieuwe schaal.
 */
class SeasonObserver
{
    public function updating(Season $season): void
    {
        if (! $season->isDirty('points_per_set') || ! $season->rounds()->exists()) {
            return;
        }

        throw SeasonScaleIsFixed::for($season);
    }

    public function updated(Season $season): void
    {
        if (! $season->wasChanged('points_per_set')) {
            return;
        }

        $this->rescaleBasePoints($season);
    }

    /**
     * Basispunten zijn de startpunten van de schaal (14.0 of 19.0) plus 0.0001 per
     * plaats hoger in de eindstand van het vorige seizoen. Ze allemaal verschuiven
     * met het verschil tussen beide startpunten zet ze op de nieuwe schaal en houdt
     * de onderlinge volgorde exact intact — ook die van een speler die later met
     * afwijkende basispunten is toegevoegd.
     */
    private function rescaleBasePoints(Season $season): void
    {
        $previous = PointsPerSet::from((int) $season->getRawOriginal('points_per_set'));

        $shift = $season->points_per_set->startingBasePoints() - $previous->startingBasePoints();

        $season->playerStatistics()->increment('base_points', $shift);
    }
}
