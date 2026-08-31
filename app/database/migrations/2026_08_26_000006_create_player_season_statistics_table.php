<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('player_season_statistics', function (Blueprint $table) {
            $table->id();
            $table->foreignId('season_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained()->restrictOnDelete();
            $table->double('base_points')->default(0);
            $table->unsignedInteger('sets_played')->default(0);
            $table->unsignedInteger('sets_won')->default(0);
            $table->unsignedInteger('points_played')->default(0);
            $table->unsignedInteger('points_won')->default(0);
            $table->unsignedInteger('rounds_present')->default(0);
            $table->unsignedInteger('games_played')->default(0);
            $table->timestamps();

            $table->unique(['season_id', 'player_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('player_season_statistics');
    }
};
