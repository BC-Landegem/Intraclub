<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Domain\Auth\Service\TokenService;
use Fig\Http\Message\StatusCodeInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Requires a valid Bearer JWT. On success the decoded claims are attached to the
 * request as the `user` attribute; otherwise a 401 JSON response is returned.
 */
final class JwtAuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private TokenService $tokenService,
        private ResponseFactoryInterface $responseFactory,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $header = $request->getHeaderLine('Authorization');
        if (preg_match('/^Bearer\s+(\S+)$/i', $header, $matches) !== 1) {
            return $this->unauthorized();
        }

        $claims = $this->tokenService->validate($matches[1]);
        if ($claims === null) {
            return $this->unauthorized();
        }

        return $handler->handle($request->withAttribute('user', $claims));
    }

    private function unauthorized(): ResponseInterface
    {
        $response = $this->responseFactory->createResponse(StatusCodeInterface::STATUS_UNAUTHORIZED);
        $response->getBody()->write((string) json_encode(['error' => ['message' => 'Niet geautoriseerd']]));

        return $response->withHeader('Content-Type', 'application/json');
    }
}
