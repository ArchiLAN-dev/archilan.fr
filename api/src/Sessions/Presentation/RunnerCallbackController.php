<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\SessionLifecycleManager;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class RunnerCallbackController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionLifecycleManager $sessionLifecycleManager,
        private string $centralApiSecret,
    ) {
    }

    #[Route('/api/v1/internal/sessions/{sessionId}/runner-callback', methods: ['POST'])]
    public function callback(Request $request, string $sessionId): JsonResponse
    {
        $provided = $request->headers->get('x-internal-secret', '');

        if ('' === $this->centralApiSecret || $provided !== $this->centralApiSecret) {
            return $this->apiAccessGuard->errorResponse('unauthorized', 'Secret runner invalide.', 401);
        }

        $body = $this->jsonBody($request);
        $newStatus = is_string($body['status'] ?? null) ? $body['status'] : '';

        if ('logs' === $newStatus) {
            $output = is_string($body['output'] ?? null) ? $body['output'] : '';
            $result = $this->sessionLifecycleManager->storeLogs($sessionId, $output);

            if (!$result['found']) {
                return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
            }

            return new JsonResponse(['data' => ['ok' => true]]);
        }

        if ('archived' === $newStatus) {
            $archivedSavePath = is_string($body['archived_save_path'] ?? null) ? $body['archived_save_path'] : null;
            $archivedSpoilerPath = is_string($body['archived_spoiler_path'] ?? null) ? $body['archived_spoiler_path'] : null;
            $rawSlots = $body['slots'] ?? [];
            $slots = [];
            if (is_array($rawSlots)) {
                foreach ($rawSlots as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $entry = [];
                    foreach ($item as $k => $v) {
                        if (is_string($k)) {
                            $entry[$k] = $v;
                        }
                    }
                    $slots[] = $entry;
                }
            }

            $result = $this->sessionLifecycleManager->storeArchive($sessionId, $archivedSavePath, $archivedSpoilerPath, $slots);

            if (!$result['found']) {
                return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
            }

            return new JsonResponse(['data' => ['ok' => true]]);
        }

        return new JsonResponse(['data' => ['ok' => true]]);
    }

    /**
     * @return array<string, mixed>
     */
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
