<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Wanneer het automatische pushbericht over deze speeldag vertrok. De vlag
 * is_calculated wipt op een speelavond meermaals (elke golf nieuwe matchen zet
 * ze terug op false), dus die kan niet dienen om "één bericht per speeldag" af
 * te dwingen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rounds', function (Blueprint $table): void {
            $table->timestamp('push_notified_at')->nullable()->after('is_calculated');
        });
    }

    public function down(): void
    {
        Schema::table('rounds', function (Blueprint $table): void {
            $table->dropColumn('push_notified_at');
        });
    }
};
