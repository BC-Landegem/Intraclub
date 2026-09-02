<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            // Bestaande seizoenen zijn tot 21 gespeeld; nieuwe kiezen 15 of 21.
            $table->unsignedTinyInteger('points_per_set')->default(21)->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->dropColumn('points_per_set');
        });
    }
};
