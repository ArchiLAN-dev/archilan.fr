<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Presentation;

use App\WeeklyRuns\Application\RecordWeeklyGoal;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class WeeklyGoalCallbackController
{
    public function __construct(
        private RecordWeeklyGoal $recordWeeklyGoal,
        private string $centralApiSecret,
    ) {
    }

    #[Route('/api/v1/internal/weekly-runs/goal-callback', name: 'api_weekly_runs_goal_callback', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $secret = $request->headers->get('X-Internal-Secret') ?? '';

        if (!hash_equals($this->centralApiSecret, $secret)) {
            return new JsonResponse(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        try {
            $decoded = json_decode($request->getContent(), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return new JsonResponse(['error' => 'invalid_payload'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (!is_array($decoded)) {
            return new JsonResponse(['error' => 'invalid_payload'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        /** @var array<string, mixed> $body */
        $body = $decoded;

        $externalSessionId = isset($body['externalSessionId']) && is_string($body['externalSessionId']) ? $body['externalSessionId'] : null;
        $checksTotal = isset($body['checksTotal']) && is_int($body['checksTotal']) ? $body['checksTotal'] : null;
        $itemsTotal = isset($body['itemsTotal']) && is_int($body['itemsTotal']) ? $body['itemsTotal'] : null;
        $goalReachedAtRaw = isset($body['goalReachedAt']) && is_string($body['goalReachedAt']) ? $body['goalReachedAt'] : null;

        if (null === $externalSessionId || null === $checksTotal || null === $itemsTotal || null === $goalReachedAtRaw) {
            return new JsonResponse(['error' => 'invalid_payload'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $goalReachedAt = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $goalReachedAtRaw);
        if (false === $goalReachedAt) {
            return new JsonResponse(['error' => 'invalid_goal_reached_at'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->recordWeeklyGoal->execute($externalSessionId, $checksTotal, $itemsTotal, $goalReachedAt);

        if (null === $result) {
            return new JsonResponse(['data' => null]);
        }

        return new JsonResponse(['data' => $result]);
    }
}
