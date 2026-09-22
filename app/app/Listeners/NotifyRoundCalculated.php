<?php

namespace App\Listeners;

use App\Events\RoundCalculated;
use App\Services\Push\RoundNotifier;

class NotifyRoundCalculated
{
    public function __construct(private readonly RoundNotifier $notifier) {}

    public function handle(RoundCalculated $event): void
    {
        $this->notifier->notifyIfDue($event->round);
    }
}
