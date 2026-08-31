<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archief van de intraclub vóór het huidige systeem: de generaties `comp_*` (2009-2013)
 * en `intra_*` (2013-2023) uit de oude sitedatabank.
 *
 * Bewust náást `games` en niet erin: die tabel gaat uit van vier spelers met per set
 * roterende teams, terwijl het oude format vaste teams speelde in best-of-3 — de derde
 * set werd niet gespeeld zodra een team er twee had. Oude uitslagen in `games` schuiven
 * zou elke herberekening stilzwijgend fout maken. Deze tabellen zijn alleen-lezen: ze
 * worden gevuld door `intraclub:import-archive` en nooit door de app zelf.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archive_players', function (Blueprint $table) {
            $table->id();
            // Gevuld wanneer deze speler nog bestaat in het huidige ledenbestand.
            // Leeg voor wie gestopt is vóór het huidige systeem in gebruik werd genomen.
            $table->foreignId('player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('gender', 10)->nullable();
            // Enkelklassement zoals het oude systeem het bijhield (Recreant, D, C2, ... A).
            $table->string('ranking', 16)->nullable();
            $table->unsignedInteger('comp_id')->nullable()->unique();
            $table->unsignedInteger('intra_id')->nullable()->unique();
            $table->timestamps();

            $table->index('player_id');
        });

        Schema::create('archive_seasons', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
            $table->string('source', 8); // comp of intra
            $table->unsignedInteger('source_id')->nullable();
            $table->timestamps();
        });

        Schema::create('archive_rounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archive_season_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('number');
            $table->date('date');
            $table->double('average_absent')->nullable();
            $table->string('source', 8);
            $table->unsignedInteger('source_id');
            $table->timestamps();

            $table->unique(['source', 'source_id']);
            $table->index(['archive_season_id', 'number']);
        });

        Schema::create('archive_games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archive_round_id')->constrained()->cascadeOnDelete();
            // Vaste teams: team 1 speelt alle sets tegen team 2. "home" = team 1.
            $table->foreignId('team1_player1_id')->constrained('archive_players')->restrictOnDelete();
            $table->foreignId('team1_player2_id')->constrained('archive_players')->restrictOnDelete();
            $table->foreignId('team2_player1_id')->constrained('archive_players')->restrictOnDelete();
            $table->foreignId('team2_player2_id')->constrained('archive_players')->restrictOnDelete();
            // Signed, in tegenstelling tot `games`: de oude data bevat minstens één
            // wedstrijd met -1 als setstand. Een archief bewaart dat zoals het was in
            // plaats van het stilzwijgend recht te trekken.
            $table->smallInteger('set1_home');
            $table->smallInteger('set1_away');
            $table->smallInteger('set2_home');
            $table->smallInteger('set2_away');
            // Leeg wanneer de match al na twee sets beslist was.
            $table->smallInteger('set3_home')->nullable();
            $table->smallInteger('set3_away')->nullable();
            $table->string('source', 8);
            $table->unsignedInteger('source_id');
            $table->timestamps();

            $table->unique(['source', 'source_id']);
        });

        Schema::create('archive_player_season_statistics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archive_season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('archive_player_id')->constrained()->cascadeOnDelete();
            $table->double('base_points')->nullable();
            // Eindstand zoals het oude systeem ze publiceerde; enkel bekend voor comp_*.
            $table->double('final_points')->nullable();
            $table->unsignedInteger('sets_played')->default(0);
            $table->unsignedInteger('sets_won')->default(0);
            $table->unsignedInteger('points_played')->default(0);
            $table->unsignedInteger('points_won')->default(0);
            // Deze drie komen niet uit de oude tabellen maar worden bij de import uit
            // de uitslagen afgeleid: geen van beide generaties hield ze betrouwbaar bij
            // (zie ImportArchive::berekenTellers).
            $table->unsignedInteger('games_played')->default(0);
            $table->unsignedInteger('games_won')->default(0);
            $table->unsignedInteger('rounds_present')->default(0);
            $table->timestamps();

            $table->unique(['archive_season_id', 'archive_player_id'], 'archive_season_player_unique');
        });

        Schema::create('archive_player_round_statistics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('archive_round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('archive_player_id')->constrained()->cascadeOnDelete();
            // Voortschrijdend puntengemiddelde na deze speeldag: de klassementswaarde.
            $table->double('average');
            $table->timestamps();

            $table->unique(['archive_round_id', 'archive_player_id'], 'archive_round_player_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archive_player_round_statistics');
        Schema::dropIfExists('archive_player_season_statistics');
        Schema::dropIfExists('archive_games');
        Schema::dropIfExists('archive_rounds');
        Schema::dropIfExists('archive_seasons');
        Schema::dropIfExists('archive_players');
    }
};
