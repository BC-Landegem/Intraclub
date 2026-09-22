<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Eén rij per toestel dat pushberichten wil. Bewust niet meer dan wat nodig is
 * om te versturen: geen IP, geen user-agent, geen koppeling aan een lid — de
 * privacyverklaring op de site belooft dat.
 *
 * Het endpoint is een URL van soms meer dan 500 tekens; een unieke index op
 * zo'n kolom past niet in de sleutellimiet van MariaDB met utf8mb4. Daarom een
 * sha256 ernaast als sleutel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table): void {
            $table->id();
            $table->char('endpoint_hash', 64)->unique();
            $table->text('endpoint');
            $table->string('p256dh');
            $table->string('auth');
            $table->json('topics');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
