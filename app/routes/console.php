<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * De wachtrij (pushberichten, App\Jobs\SendPushMessage). De host heeft geen
 * blijvende processen, dus geen `queue:work` dat altijd draait; in plaats
 * daarvan roept een cron in DirectAdmin elke minuut `schedule:run` aan (zie
 * DEPLOY.md), en die werkt de wachtrij leeg en stopt.
 *
 * De vier getallen hieronder hangen aan elkaar, en in deze volgorde:
 *
 *   max-time 50 < timeout 240: max-time stopt enkel het aannemen van nieuwe
 *     jobs, niet de lopende. Eén bericht is één job die sequentieel naar alle
 *     abonnees stuurt, en die mag dus langer duren dan een cron-minuut.
 *   timeout 240 < retry_after 300 (config/queue.php): staat het andersom, dan
 *     geeft de databank de job aan een tweede worker terwijl de eerste nog aan
 *     het versturen is, en krijgt iedereen het bericht twee keer.
 *   withoutOverlapping(5): de vergrendeling moet 50 + 240 overleven, maar
 *     vooral niet de standaard 24 uur zijn — wordt een run hard afgebroken, dan
 *     blijft dat slot staan en vertrekt er een dag lang geen enkel bericht.
 */
Schedule::command('queue:work', ['--stop-when-empty', '--max-time=50', '--timeout=240', '--tries=3'])
    ->everyMinute()
    ->withoutOverlapping(5)
    ->runInBackground();

Schedule::command('queue:prune-failed', ['--hours' => 24 * 30])->daily();
