<?php

declare(strict_types=1);

namespace App\Action\Auth;

use App\Domain\Auth\Service\Authenticator;
use App\Renderer\JsonRenderer;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class LoginAction
{
    public function __construct(
        private Authenticator $authenticator,
        private JsonRenderer $renderer,
    ) {
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $data = (array) $request->getParsedBody();
        $result = $this->authenticator->attempt(
            (string) ($data['username'] ?? ''),
            (string) ($data['password'] ?? ''),
            time(),
        );

        if ($result === null) {
            return $this->renderer
                ->json($response, ['error' => ['message' => 'Ongeldige gebruikersnaam of wachtwoord']])
                ->withStatus(StatusCodeInterface::STATUS_UNAUTHORIZED);
        }

        return $this->renderer->json($response, $result);
    }
}
