<?php

declare(strict_types=1);

namespace App\Action\Auth;

use App\Domain\Auth\Service\Authenticator;
use App\Domain\Auth\Service\LoginThrottle;
use App\Factory\LoggerFactory;
use App\Renderer\JsonRenderer;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;

final class LoginAction
{
    private LoggerInterface $logger;

    public function __construct(
        private Authenticator $authenticator,
        private LoginThrottle $throttle,
        private JsonRenderer $renderer,
        LoggerFactory $loggerFactory,
    ) {
        $this->logger = $loggerFactory->addFileHandler('auth.log')->createLogger('auth');
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $now = time();
        $clientIp = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? 'unknown');

        if ($this->throttle->tooManyAttempts($clientIp, $now)) {
            $this->logger->warning('Login throttled', ['ip' => $clientIp]);

            return $this->renderer
                ->json($response, ['error' => ['message' => 'Te veel inlogpogingen. Probeer later opnieuw.']])
                ->withStatus(StatusCodeInterface::STATUS_TOO_MANY_REQUESTS)
                ->withHeader('Retry-After', (string) $this->throttle->retryAfter());
        }

        $data = (array) $request->getParsedBody();
        $username = (string) ($data['username'] ?? '');
        $result = $this->authenticator->attempt($username, (string) ($data['password'] ?? ''), $now);

        if ($result === null) {
            $this->throttle->recordFailure($clientIp, $now);
            $this->logger->warning('Failed login', ['username' => $username, 'ip' => $clientIp]);

            return $this->renderer
                ->json($response, ['error' => ['message' => 'Ongeldige gebruikersnaam of wachtwoord']])
                ->withStatus(StatusCodeInterface::STATUS_UNAUTHORIZED);
        }

        $this->throttle->clear($clientIp);
        $this->logger->info('Successful login', ['username' => $username, 'ip' => $clientIp]);

        return $this->renderer->json($response, $result);
    }
}
