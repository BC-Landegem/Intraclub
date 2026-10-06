<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Een uitgelote speler die naar huis gaat, is niet meer in de zaal maar wás er
 * wel: `is_present` blijft staan voor de geschiedenis (aanwezigheidsreeksen,
 * tellers op de website) en dit zegt de zaal en de loting dat hij weg is.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('player_round_statistics', function (Blueprint $table): void {
            $table->boolean('has_left')->default(false)->after('is_drawn_out_unconfirmed');
        });
    }

    public function down(): void
    {
        Schema::table('player_round_statistics', function (Blueprint $table): void {
            $table->dropColumn('has_left');
        });
    }
};
