<?php

/**
 * Handmatig bevestigde koppelingen voor build-player-map.php.
 *
 * Naamgelijkenis vindt de meeste spelers terug, maar niet allemaal: spelfouten,
 * omgekeerde volgorde en naamswijzigingen vragen een menselijke beslissing. Wat
 * hier staat is nagekeken en overrulet de automatische matching, zodat een nieuwe
 * dump geen tweede reviewronde nodig heeft.
 *
 * Nagekeken en bevestigd op 2026-08-27.
 */

return [
    /*
     * Dezelfde persoon staat tweemaal in de huidige `player`-tabel. De sleutel is het
     * dubbel, de waarde de speler die blijft. LET OP: dit lost enkel de mapping op —
     * in productie moeten deze twee spelers nog echt samengevoegd worden, inclusief
     * hun wedstrijden en statistieken.
     */
    'player_dubbels' => [
        132 => 36, // David Inghels, tweede keer aangemaakt met een geboortedatum 4 dagen naast de eerste
        145 => 72, // Lieselot Van Haute, identieke geboortedatum
    ],

    /*
     * Speler uit intra_spelers (2013-2023) die in het huidige systeem onder een
     * afwijkende schrijfwijze staat. Sleutel = intra-id, waarde = player-id.
     */
    'intra_naar_player' => [
        127 => 78, // Sander Vandedrinck; in `player` staat "Vanderdrinck"
    ],

    /*
     * Speler uit comp_spelers (2009-2013) die dezelfde persoon is als een
     * intra-speler. Sleutel = comp-id, waarde = intra-id.
     */
    'comp_naar_intra' => [
        2 => 52,    // Luc Heirbrant / Heirbrandt
        117 => 29,  // Jonathan Seyvage / Servayge
    ],
];
