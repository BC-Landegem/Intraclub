<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('games', function (Blueprint $table) {
            $table->id();
            $table->foreignId('round_id')->constrained()->cascadeOnDelete();
            $table->foreignId('home_player1_id')->constrained('players')->restrictOnDelete();
            $table->foreignId('home_player2_id')->constrained('players')->restrictOnDelete();
            $table->foreignId('away_player1_id')->constrained('players')->restrictOnDelete();
            $table->foreignId('away_player2_id')->constrained('players')->restrictOnDelete();
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
