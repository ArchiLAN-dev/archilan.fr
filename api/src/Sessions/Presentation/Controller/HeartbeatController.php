<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Sessions\Application\Service\SessionLifecycleManager;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\WeeklyRuns\Application\Query\WeeklyEntrySessionCheck;
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

        $result = $this->sessionLifecycleManager->heartbeat($sessionId, self::connectedSlotNames($request));

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

    /**
     * The slots a game client holds, from `{"connected_slots": [{"slot": 3, "name": "Zoe"}]}` (story 32.26). A
     * bridge older than the story sends no body: nobody.
     *
     * @return list<string>
     */
    private static function connectedSlotNames(Request $request): array
    {
        $body = json_decode($request->getContent(), true);
        $slots = \is_array($body) ? ($body['connected_slots'] ?? null) : null;
        if (!\is_array($slots)) {
            return [];
        }

        $names = [];
        foreach ($slots as $slot) {
            $name = \is_array($slot) ? ($slot['name'] ?? null) : null;
            if (\is_string($name) && '' !== $name) {
                $names[] = $name;
            }
        }

        return $names;
    }
}
