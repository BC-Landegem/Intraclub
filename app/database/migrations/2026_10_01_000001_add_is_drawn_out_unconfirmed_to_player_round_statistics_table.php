<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Een uitloting is voorlopig tot de eerste match na die loting bevestigd is: wie
 * vóór dat moment opnieuw loot, gooit ze weg. Daarna is ze definitief en blijft ze
 * staan, ook als de speler later die avond nog een match speelt.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_round_statistics', function (Blueprint $table): void {
            $table->boolean('is_drawn_out_unconfirmed')->default(false)->after('is_drawn_out');
        });
    }

    public function down(): void
    {
        Schema::table('player_round_statistics', function (Blueprint $table): void {
            $table->dropColumn('is_drawn_out_unconfirmed');
        });
    }
};
