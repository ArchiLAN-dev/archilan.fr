<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\SendBridgeCommand;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CommandsController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SendBridgeCommand $sendBridgeCommand,
    ) {
    }

    #[Route('/api/v1/admin/sessions/{id}/commands', methods: ['POST'])]
    public function commands(Request $request, string $id): JsonResponse
    {
        $user = $this->requireAuthenticatedAdmin($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $body = $this->jsonBody($request);
        $command = is_string($body['command'] ?? null) ? trim($body['command']) : '';
        if ('' === $command) {
            return $this->apiAccessGuard->errorResponse('invalid_command', 'La commande est requise.', 422);
        }

        $result = $this->sendBridgeCommand->execute($id, $command, $user->getId());

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        if ('session_not_running' === $result['error']) {
            return $this->apiAccessGuard->errorResponse('session_not_running', 'La session n\'est pas en cours.', 409);
        }

        if ('bridge_unavailable' === $result['error']) {
            return $this->apiAccessGuard->errorResponse('bridge_unavailable', 'Bridge non disponible.', 503);
        }

        return new JsonResponse(['data' => ['ok' => true]]);
    }

    /** @return array<string, mixed> */
    private function jsonBody(Request $request): array
    {
        try {
            $decoded = json_decode($request->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);

            if (!is_array($decoded)) {
                return [];
            }

            $result = [];
            foreach ($decoded as $key => $value) {
                if (is_string($key)) {
                    $result[$key] = $value;
                }
            }

            return $result;
        } catch (\JsonException) {
            return [];
        }
    }
}
