<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\SessionLifecycleManager;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class SessionActivityController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionLifecycleManager $sessionLifecycleManager,
        private string $bridgeInternalToken,
    ) {
    }

    #[Route('/api/v1/sessions/{sessionId}/activity', methods: ['PATCH'])]
    public function activity(Request $request, string $sessionId): JsonResponse
    {
        $auth = $request->headers->get('Authorization') ?? '';

        if (
            '' === $this->bridgeInternalToken
            || !str_starts_with($auth, 'Bearer ')
            || substr($auth, 7) !== $this->bridgeInternalToken
        ) {
            return $this->apiAccessGuard->errorResponse('unauthorized', 'Token invalide.', 401);
        }

        $body = $this->jsonBody($request);

        $occurredAtRaw = $body['occurredAt'] ?? null;
        $occurredAt = new \DateTimeImmutable();
        if (is_string($occurredAtRaw) && '' !== $occurredAtRaw) {
            try {
                $occurredAt = new \DateTimeImmutable($occurredAtRaw);
            } catch (\Throwable) {
                // Fall back to server time on invalid ISO8601
            }
        }

        $result = $this->sessionLifecycleManager->recordActivity($sessionId, $occurredAt);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
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
