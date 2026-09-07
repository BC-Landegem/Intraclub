<?php

/*
 * Het klassement (App\Services\RankingService).
 *
 * LET OP: na `php artisan optimize` staat de configuratie in de cache. Pas je
 * .env aan, dan moet de taak `optimize` opnieuw draaien voor de wijziging
 * aankomt — zie DEPLOY.md. Daarvoor is geen nieuwe deploy van de code nodig.
 */

return [

    /*
     * In hoeveel recentste berekende speeldagen een speler minstens één match
     * moet gespeeld hebben om nog als actief te gelden. Wie dat niet haalt zakt
     * naar onderaan het klassement en toont geen gemiddelde meer.
     *
     * 0 zet de regel uit: dan is iedereen actief, zoals voordien.
     */
    'active_rounds' => (int) env('RANKING_ACTIVE_ROUNDS', 3),

];
