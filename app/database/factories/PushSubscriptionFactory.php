<?php

namespace Database\Factories;

use App\Models\PushSubscription;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PushSubscription>
 */
class PushSubscriptionFactory extends Factory
{
    protected $model = PushSubscription::class;

    public function definition(): array
    {
        $endpoint = 'https://fcm.googleapis.com/fcm/send/'.Str::random(40);

        return [
            'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
            'endpoint' => $endpoint,
            'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
            'auth' => 'tBHItJI5svbpez7KI4CCXg',
            'topics' => ['club', 'intraclub'],
        ];
    }

    /** @param  list<string>  $topics */
    public function topics(array $topics): static
    {
        return $this->state(fn (): array => ['topics' => $topics]);
    }
}
