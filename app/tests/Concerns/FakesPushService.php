<?php

namespace Tests\Concerns;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Minishlink\WebPush\VAPID;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;

/**
 * Speelt de pushdienst na: WebPushSender krijgt een Guzzle-client die de
 * opgegeven antwoorden in volgorde teruggeeft, en elk verzoek dat vertrok
 * staat in $pushRequests. Zo raakt geen test het netwerk, en is te zien wat er
 * naar welk endpoint ging.
 */
trait FakesPushService
{
    /** @var list<array{request: RequestInterface}> */
    protected array $pushRequests = [];

    /** Sleutels eenmaal per testrun: aanmaken kost rekentijd. */
    private static ?array $vapidKeys = null;

    protected function configurePush(): void
    {
        self::$vapidKeys ??= VAPID::createVapidKeys();

        config([
            'push.vapid.public_key' => self::$vapidKeys['publicKey'],
            'push.vapid.private_key' => self::$vapidKeys['privateKey'],
            'push.vapid.subject' => 'mailto:test@bclandegem.be',
            'push.site_url' => 'https://www.bclandegem.be',
        ]);
    }

    /**
     * @param  list<int>  $statuses  HTTP-statussen die de pushdienst achtereenvolgens antwoordt
     */
    protected function fakePushService(array $statuses): void
    {
        $this->configurePush();
        $this->pushRequests = [];

        $handler = HandlerStack::create(new MockHandler(array_map(
            fn (int $status): Response => new Response($status),
            $statuses,
        )));
        $handler->push(Middleware::history($this->pushRequests));

        $this->app->instance(ClientInterface::class, new Client(['handler' => $handler]));
    }

    /** @return list<string> */
    protected function pushedEndpoints(): array
    {
        return array_map(fn (array $entry): string => (string) $entry['request']->getUri(), $this->pushRequests);
    }

    /** Geldige sleutels zoals een browser ze aanlevert (65 en 16 bytes, base64url). */
    protected function subscriptionPayload(string $endpoint, array $extra = []): array
    {
        return [
            'endpoint' => $endpoint,
            'expirationTime' => null,
            'keys' => [
                'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Ts1XbjhazAkj7I99e8QcYP7DkM',
                'auth' => 'tBHItJI5svbpez7KI4CCXg',
            ],
            ...$extra,
        ];
    }
}
