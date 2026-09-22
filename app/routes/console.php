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
 * DEPLOY.md), en die werkt de wachtrij leeg en stopt. --max-time houdt hem
 * onder de minuut zodat twee runs elkaar niet overlappen; withoutOverlapping
 * vangt het als het toch gebeurt.
 */
Schedule::command('queue:work', ['--stop-when-empty', '--max-time=50', '--tries=3'])
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

Schedule::command('queue:prune-failed', ['--hours' => 24 * 30])->daily();
