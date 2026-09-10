<?php

use App\Enums\DrawSystem;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            // De bestaande seizoenen zijn met de sterktegroepen geloot; de kolom is
            // een feit over hoe het seizoen gelopen heeft en mag daar niet over liegen.
            $table->string('draw_system', 32)
                ->default(DrawSystem::StrengthGroups->value)
                ->after('points_per_set');
        });
    }

    public function down(): void
    {
        Schema::table('seasons', function (Blueprint $table) {
            $table->dropColumn('draw_system');
        });
    }
};
