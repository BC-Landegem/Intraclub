<?php

namespace App\Services;

/**
 * De stand waarop de twee duo's aan een set beginnen.
 *
 * De bonuspunten van een speler (`Player::bonusPoints`) zeggen hoe zwak hij staat;
 * de som per duo dus hoe zwak dat duo staat. Het verschil tussen de twee sommen is
 * de handicap H, en die wordt **gesplitst**: het zwakke duo begint op `ceil(H/2)`,
 * het sterke op `-floor(H/2)`. Bij H=6 is dat 3 en −3, bij H=7 4 en −3. De afstand
 * tussen de twee blijft exact H; alleen ligt ze rond nul in plaats van erboven.
 *
 * Waarom dat uitmaakt: het klassement rekent met het getrimde puntengemiddelde per
 * set, niet met gewonnen sets. Gaf je de volle H aan het zwakke duo, dan gingen die
 * gratis punten rechtstreeks in hun seizoensgemiddelde — over de productiedump
 * gemeten 3,02 punten per set, volledig naar één kant. Gesplitst is dat 0,50, en
 * daarmee is de handicap zo goed als klassementsneutraal.
 *
 * Dit is een afspraak op het terrein en géén rekenregel: de handicap komt niet in
 * `SeasonCalculator`, niet in `GameStatistics` en niet in de scorevalidatie. Wat
 * bewaard wordt is de bordstand, met de startstand er al in verwerkt.
 */
readonly class Handicap
{
    private function __construct(
        /** De stand waarop het thuisduo van die set begint. */
        public int $home,
        /** De stand waarop het uitduo van die set begint. */
        public int $away,
    ) {}

    /**
     * De startstanden voor een set, uit de bonussommen van de twee duo's.
     *
     * Gelijke sommen geven 0 en 0 — geen uitzondering, gewoon de regel bij H=0.
     */
    public static function between(int $homeBonus, int $awayBonus): self
    {
        $difference = $homeBonus - $awayBonus;
        $steps = abs($difference);

        // Bij oneven H gaat het overschietende punt naar het zwakke duo.
        $weaker = intdiv($steps + 1, 2);
        $stronger = -intdiv($steps, 2);

        // Meer bonuspunten = zwakker, dus het duo met de hoogste som krijgt de voorsprong.
        return $difference >= 0
            ? new self(home: $weaker, away: $stronger)
            : new self(home: $stronger, away: $weaker);
    }
}
