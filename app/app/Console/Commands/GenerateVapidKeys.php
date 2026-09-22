<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/**
 * Maakt een VAPID-sleutelpaar voor de pushberichten. Lokaal draaien en de twee
 * regels in de .env van de omgeving plakken, zoals APP_KEY: de host heeft geen
 * artisan. Eén paar per omgeving, en bewaren — een nieuw paar maakt elk bestaand
 * abonnement waardeloos.
 */
class GenerateVapidKeys extends Command
{
    protected $signature = 'push:vapid';

    protected $description = 'Genereer een VAPID-sleutelpaar voor de pushberichten (plak de uitvoer in .env)';

    public function handle(): int
    {
        $keys = VAPID::createVapidKeys();

        $this->line("VAPID_PUBLIC_KEY={$keys['publicKey']}");
        $this->line("VAPID_PRIVATE_KEY={$keys['privateKey']}");
        $this->newLine();
        $this->comment('De publieke sleutel komt ook als PUBLIC_VAPID_PUBLIC_KEY in de build van de site (repository variable in de Website-repo).');

        return self::SUCCESS;
    }
}
