<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;

/**
 * Zet het wachtwoord van een beheerder.
 *
 * Op de shared hosting is er geen SSH, dus het beheerdersaccount moet mee in de export
 * naar productie — met de hash die daar moet gelden. Dit commando bestaat zodat je die
 * vóór het exporteren kan zetten zonder een tinker-oneliner die per shell anders gequote
 * moet worden.
 */
class SetPassword extends Command
{
    protected $signature = 'intraclub:set-password
        {email? : E-mailadres van de gebruiker}';

    protected $description = 'Zet het wachtwoord van een beheerder (vraagt het interactief op).';

    public function handle(): int
    {
        $email = $this->argument('email') ?? $this->kiesGebruiker();

        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("Geen gebruiker met e-mailadres {$email}.");
            $this->toonBestaande();

            return self::FAILURE;
        }

        $wachtwoord = password('Nieuw wachtwoord', required: true);

        if ($wachtwoord !== password('Nogmaals ter bevestiging', required: true)) {
            $this->error('De twee wachtwoorden komen niet overeen.');

            return self::FAILURE;
        }

        // De `hashed`-cast op het model doet de bcrypt-hashing, dus dit blijft in lijn
        // met wat de login verwacht.
        $user->update(['password' => $wachtwoord]);

        $this->info("Wachtwoord van {$user->email} is aangepast.");

        return self::SUCCESS;
    }

    private function kiesGebruiker(): string
    {
        $gebruikers = User::query()->orderBy('email')->pluck('email', 'email')->all();

        if ($gebruikers === []) {
            $this->error('Er zijn nog geen gebruikers. Maak er een aan met make:filament-user.');

            exit(self::FAILURE);
        }

        return select('Welke gebruiker?', $gebruikers);
    }

    /** Zonder dit weet je bij een tikfout niet welk adres je dan wél moet nemen. */
    private function toonBestaande(): void
    {
        $gebruikers = User::query()->orderBy('email')->get(['name', 'email']);

        if ($gebruikers->isEmpty()) {
            $this->line('Er zijn nog geen gebruikers. Maak er een aan met: php artisan make:filament-user');

            return;
        }

        $this->newLine();
        $this->line('Bestaande gebruikers:');
        foreach ($gebruikers as $gebruiker) {
            $this->line("  {$gebruiker->email}  ({$gebruiker->name})");
        }
        $this->newLine();
        $this->line('Een nieuwe beheerder toevoegen: php artisan make:filament-user');
    }
}
