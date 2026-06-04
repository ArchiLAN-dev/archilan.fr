<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\WeeklyRuns\Application\RecordWeeklyGoal;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Generic slot-goal callback called by the bridge when any slot reaches its goal.
 * Symfony dispatches to the appropriate domain handler based on the session type.
 */
final readonly class SlotGoalCallbackController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private RecordWeeklyGoal $recordWeeklyGoal,
        private string $centralApiSecret,
    ) {
    }

    #[Route('/api/v1/internal/sessions/{sessionId}/slot-goal', methods: ['POST'])]
    public function __invoke(Request $request, string $sessionId): JsonResponse
    {
        $provided = $request->headers->get('X-Internal-Secret', '');
        if ('' === $this->centralApiSecret || $provided !== $this->centralApiSecret) {
            return $this->apiAccessGuard->errorResponse('unauthorized', 'Secret invalide.', 401);
        }

        try {
            $body = $request->toArray();
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('invalid_payload', 'JSON invalide.', 422);
        }

        $checksTotal = is_int($body['checksTotal'] ?? null) ? $body['checksTotal'] : null;
        $itemsTotal = is_int($body['itemsTotal'] ?? null) ? $body['itemsTotal'] : null;
        $goalReachedAtRaw = is_string($body['goalReachedAt'] ?? null) ? $body['goalReachedAt'] : null;

        if (null === $checksTotal || null === $itemsTotal || null === $goalReachedAtRaw) {
            return $this->apiAccessGuard->errorResponse('invalid_payload', 'Champs manquants.', 422);
        }

        try {
            $goalReachedAt = new \DateTimeImmutable($goalReachedAtRaw);
        } catch (\Exception) {
            return $this->apiAccessGuard->errorResponse('invalid_payload', 'goalReachedAt invalide.', 422);
        }

        // Weekly entry: session ID = external session ID on WeeklyEntry
        $result = $this->recordWeeklyGoal->execute($sessionId, $checksTotal, $itemsTotal, $goalReachedAt);

        return new JsonResponse(['data' => $result], Response::HTTP_OK);
    }
}
