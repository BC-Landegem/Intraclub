<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Eén game = 3 sets met roterende teams onder 4 spelers:
     * set 1: (P1,P2) vs (P3,P4) — set 2: (P1,P3) vs (P2,P4) — set 3: (P1,P4) vs (P2,P3).
     * "home" in de setscores = het eerste team van die set.
     */
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player1_id')->constrained('players')->restrictOnDelete();
            $table->foreignId('player2_id')->constrained('players')->restrictOnDelete();
            $table->foreignId('player3_id')->constrained('players')->restrictOnDelete();
            $table->foreignId('player4_id')->constrained('players')->restrictOnDelete();
            $table->unsignedTinyInteger('set1_home')->nullable();
            $table->unsignedTinyInteger('set1_away')->nullable();
            $table->unsignedTinyInteger('set2_home')->nullable();
            $table->unsignedTinyInteger('set2_away')->nullable();
            $table->unsignedTinyInteger('set3_home')->nullable();
            $table->unsignedTinyInteger('set3_away')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('games');
    }
};
