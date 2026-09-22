<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Logboek van verzonden pushberichten. Er vertrekt geen bevestiging en de
 * pushdiensten melden niets terug over aflevering, dus dit is de enige plek
 * waar te zien is dat een bericht vertrok, naar hoeveel toestellen, en hoeveel
 * dode abonnementen daarbij opgeruimd werden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_messages', function (Blueprint $table): void {
            $table->id();
            $table->string('topic', 32);
            $table->string('title', 100);
            $table->string('body', 255);
            $table->string('url', 500)->nullable();
            // Het automatische bericht van een speeldag; null voor een clubbericht.
            $table->foreignId('round_id')->nullable()->constrained()->nullOnDelete();
            // Wie het verstuurde; null voor een automatisch bericht.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('expired_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->timestamp('sent_at')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_messages');
    }
};
