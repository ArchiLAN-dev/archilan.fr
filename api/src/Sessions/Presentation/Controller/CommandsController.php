<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Sessions\Application\Command\SendBridgeCommand;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
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

        // Failures (session missing, not running, bridge down) are thrown as typed ApplicationFailures
        // and mapped to HTTP by ApplicationFailureListener (epic 35).
        $this->sendBridgeCommand->execute($id, $command, $user->getId());

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
