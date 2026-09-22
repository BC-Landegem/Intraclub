<?php

namespace App\Services\Push;

use App\Jobs\SendPushMessage;
use App\Models\PushMessage;
use App\Models\Round;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Het automatische bericht "speeldag N is berekend" op het onderwerp
 * `intraclub`, en de handmatige herzending ervan vanuit het beheerspaneel.
 *
 * Wanneer het vertrekt: bij de eerste keer dat een speeldag in de stand komt.
 * Op een speelavond wipt is_calculated meermaals (elke golf nieuwe matchen zet
 * ze terug), daarom telt push_notified_at en niet de vlag. Dat kan dus midden
 * in de avond zijn, als na golf één alle matchen even compleet zijn; dat is
 * aanvaard, de link toont altijd de actuele stand.
 *
 * Wanneer niet: een speeldag ouder dan `push.round_max_age_days`. Dat vangt de
 * import van de oude databank en de reset-workflow, die twintig speeldagen
 * tegelijk berekenen. En als er geen VAPID-sleutels zijn, want dan kan er toch
 * niets vertrekken; push_notified_at blijft dan leeg, en de datumgrens zorgt
 * dat er later niets oud naverstuurd wordt.
 *
 * Er staat bewust geen "enkel het lopende seizoen" bij. Season::current() is
 * het hoogste id, dus wie het volgende seizoen aanmaakt vóór de laatste
 * speeldagen gespeeld zijn, zou daarmee het bericht stil uitzetten — zonder
 * fout en zonder logregel. De datumgrens houdt tegen waarvoor die check
 * bedoeld was: een speeldag van een afgesloten seizoen ligt per definitie
 * verder dan drie dagen achter ons.
 */
class RoundNotifier
{
    public function notifyIfDue(Round $round): ?PushMessage
    {
        if (! WebPushSender::isConfigured() || $round->push_notified_at !== null) {
            return null;
        }

        if ($round->date->lt(today()->subDays((int) config('push.round_max_age_days')))) {
            return null;
        }

        return $this->notify($round);
    }

    /** Verstuurt (opnieuw), zonder de remmen van hierboven. */
    public function notify(Round $round, ?User $by = null): PushMessage
    {
        return DB::transaction(function () use ($round, $by): PushMessage {
            $message = PushMessage::create([
                'topic' => 'intraclub',
                'title' => "Intraclub: speeldag {$round->number} berekend",
                'body' => sprintf('De nieuwe stand na %s staat online.', $round->date->translatedFormat('j F')),
                'url' => config('push.site_url').'/intraclub/speeldag/?id='.$round->id,
                'round_id' => $round->id,
                'user_id' => $by?->id,
            ]);

            $round->forceFill(['push_notified_at' => now()])->save();

            SendPushMessage::dispatch($message->id);

            return $message;
        });
    }
}
