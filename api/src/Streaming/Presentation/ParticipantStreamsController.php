<?php

declare(strict_types=1);

namespace App\Streaming\Presentation;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Streaming\Application\ParticipantStreamsView;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public per-session endpoints listing participants' Twitch channels with live status (story 7.7).
 * Each returns the participants sorted live-first, or 404 when the session does not exist.
 */
final readonly class ParticipantStreamsController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private ParticipantStreamsView $view,
    ) {
    }

    #[Route('/api/v1/events/{eventId}/participant-streams', name: 'api_event_participant_streams', methods: ['GET'])]
    public function event(string $eventId): JsonResponse
    {
        $streams = $this->view->forEvent($eventId);
        if (null === $streams) {
            return $this->apiAccessGuard->errorResponse('event_not_found', 'Événement introuvable.', 404);
        }

        return new JsonResponse(['data' => $streams]);
    }

    #[Route('/api/v1/runs/{runId}/participant-streams', name: 'api_run_participant_streams', methods: ['GET'])]
    public function run(string $runId): JsonResponse
    {
        $streams = $this->view->forPersonalRun($runId);
        if (null === $streams) {
            return $this->apiAccessGuard->errorResponse('run_not_found', 'Run introuvable.', 404);
        }

        return new JsonResponse(['data' => $streams]);
    }

    #[Route('/api/v1/weekly-runs/{weeklyRunId}/participant-streams', name: 'api_weekly_run_participant_streams', methods: ['GET'])]
    public function weeklyRun(string $weeklyRunId): JsonResponse
    {
        $streams = $this->view->forWeeklyRun($weeklyRunId);
        if (null === $streams) {
            return $this->apiAccessGuard->errorResponse('weekly_run_not_found', 'Run hebdomadaire introuvable.', 404);
        }

        return new JsonResponse(['data' => $streams]);
    }
}
