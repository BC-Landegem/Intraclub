<?php

declare(strict_types=1);

namespace App\Action\Player;

use App\Domain\Player\Service\AttendanceUpdater;
use App\Renderer\JsonRenderer;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

final class AttendanceUpdaterAction
{
    public function __construct(
        private AttendanceUpdater $attendanceUpdater,
        private JsonRenderer $renderer
    ) {
    }

    /**
     * @param array<string, string> $args
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        array $args
    ): ResponseInterface {
        $data = (array) $request->getParsedBody();

        $this->attendanceUpdater->updateAttendance(
            (int) $args['playerId'],
            (int) $args['id'],
            (bool) ($data['present'] ?? false),
            (bool) ($data['drawnOut'] ?? false)
        );

        return $this->renderer->json($response, ['status' => 'ok']);
    }
}
