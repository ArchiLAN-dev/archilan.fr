<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\SessionLifecycleManager;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\WeeklyRuns\Application\WeeklyEntrySessionCheck;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class HeartbeatController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionLifecycleManager $sessionLifecycleManager,
        private WeeklyEntrySessionCheck $weeklyEntrySessionCheck,
        private string $centralApiSecret,
    ) {
    }

    #[Route('/api/v1/internal/sessions/{sessionId}/heartbeat', methods: ['POST'])]
    public function heartbeat(Request $request, string $sessionId): JsonResponse
    {
        $provided = $request->headers->get('x-internal-secret', '');

        if ('' === $this->centralApiSecret || $provided !== $this->centralApiSecret) {
            return $this->apiAccessGuard->errorResponse('unauthorized', 'Secret invalide.', 401);
        }

        $result = $this->sessionLifecycleManager->heartbeat($sessionId);

        if (!$result['found']) {
            // The session ID may belong to a weekly run entry - no heartbeat tracking
            // needed for those, but we must acknowledge to avoid bridge warnings.
            if ($this->weeklyEntrySessionCheck->existsByExternalSessionId($sessionId)) {
                return new JsonResponse(['data' => ['ok' => true]]);
            }

            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        return new JsonResponse(['data' => ['ok' => true]]);
    }
}
