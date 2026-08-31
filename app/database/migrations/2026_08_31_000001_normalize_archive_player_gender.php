<?php

use App\Enums\Gender;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Zet het geslacht van de archiefspelers om naar de waarden van {@see Gender}.
 *
 * De import nam over wat de bron schreef, en de twee generaties schreven het
 * anders: `intra_spelers` gebruikt "Man"/"Vrouw", `comp_spelers` "man"/"vrouw".
 * Dat leverde vier byte-waarden in één kolom op — onzichtbaar in een gewone
 * GROUP BY, want de collatie is hoofdletterongevoelig, en dus een val voor elke
 * vergelijking die dat niet is. Sinds /archive/seasons/{id}/standings het
 * geslacht publiceert, is de kolom bovendien een gedeeld begrip met `players`
 * en hoort ze dezelfde spelling te gebruiken.
 *
 * De hoofdletters gaan er hier definitief uit: ze droegen geen betekenis, dus
 * down() zet alles terug op de intra-vorm en niet op de bron per rij.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->hertaal(['man' => Gender::Male->value, 'vrouw' => Gender::Female->value]);
    }

    public function down(): void
    {
        $this->hertaal([Gender::Male->value => 'Man', Gender::Female->value => 'Vrouw']);
    }

    /** @param array<string, string> $vertaling van-waarde (kleine letters) => naar-waarde */
    private function hertaal(array $vertaling): void
    {
        foreach ($vertaling as $van => $naar) {
            DB::table('archive_players')
                ->whereRaw('LOWER(gender) = ?', [$van])
                ->update(['gender' => $naar]);
        }
    }
};
