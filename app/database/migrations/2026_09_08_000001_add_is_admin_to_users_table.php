<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /*
     * Tot nu was elke rij in `users` een beheerder. Het zaaltoestel logt met
     * hetzelfde account in als het beheerspaneel, en dat account hangt aan de
     * muur van de zaal: wie eraan kan, kon ook aan spelers en seizoenen.
     *
     * Default true, zodat de bestaande accounts (en `make:filament-user`, dat
     * deze kolom niet kent) hun toegang houden.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('is_admin')->default(true)->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('is_admin');
        });
    }
};
