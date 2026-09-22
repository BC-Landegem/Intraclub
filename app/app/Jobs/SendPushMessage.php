<?php

namespace App\Jobs;

use App\Models\PushMessage;
use App\Services\Push\WebPushSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Verstuurt een PushMessage vanuit de wachtrij. Op de host draait die via een
 * cron die elke minuut `schedule:run` aanroept (zie routes/console.php en
 * DEPLOY.md), dus een bericht vertrekt binnen de minuut na het aanmaken en
 * nooit in het request van de zaal-app of het beheerspaneel.
 */
class SendPushMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public function __construct(public readonly int $messageId) {}

    public function handle(WebPushSender $sender): void
    {
        $message = PushMessage::find($this->messageId);

        if ($message === null || $message->sent_at !== null) {
            return;
        }

        $sender->send($message);
    }

    public function failed(Throwable $exception): void
    {
        PushMessage::whereKey($this->messageId)->update([
            'error' => mb_substr($exception->getMessage(), 0, 1000),
        ]);
    }
}
