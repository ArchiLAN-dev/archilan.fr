<?php

declare(strict_types=1);

namespace App\PersonalRuns\Presentation\Controller;

use App\PersonalRuns\Application\Command\NudgeRunParticipant;
use App\PersonalRuns\Application\Command\NudgeRunParticipantOutcome;
use App\PersonalRuns\Application\Query\RunNudgesQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Nudging a player of a personal run who has not played for two days (story 43.12).
 */
final readonly class RunNudgeController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private NudgeRunParticipant $nudge,
        private RunNudgesQuery $nudges,
    ) {
    }

    #[Route('/api/v1/runs/{runId}/nudges', name: 'api_runs_nudges_list', methods: ['GET'])]
    public function list(Request $request, string $runId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $data = $this->nudges->forRun($runId, $user->getId());
        if (null === $data) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Partie introuvable.', 404);
        }

        return new JsonResponse(['data' => $data]);
    }

    #[Route('/api/v1/runs/{runId}/nudges/{userId}', name: 'api_runs_nudges_send', methods: ['POST'], requirements: ['userId' => '[0-9a-f]{32}'])]
    public function send(Request $request, string $runId, string $userId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->nudge->nudge($runId, $user->getId(), $userId);
        $at = $result->lastNudgedAt?->format(\DATE_ATOM);

        return match ($result->outcome) {
            NudgeRunParticipantOutcome::Nudged => new JsonResponse(['data' => ['lastNudgedAt' => $at]]),
            NudgeRunParticipantOutcome::NotFound => $this->apiAccessGuard->errorResponse('not_found', 'Partie introuvable.', 404),
            NudgeRunParticipantOutcome::Forbidden => $this->apiAccessGuard->errorResponse('forbidden', 'Seuls les joueurs de la partie relancent.', 403),
            NudgeRunParticipantOutcome::RunNotPlaying => $this->apiAccessGuard->errorResponse('run_not_playing', 'Cette partie n\'est pas en cours.', 409),
            NudgeRunParticipantOutcome::NotIdle => $this->apiAccessGuard->errorResponse('not_idle', 'Ce joueur ne peut pas être relancé.', 409),
            NudgeRunParticipantOutcome::Muted => $this->apiAccessGuard->errorResponse('muted', 'Ce joueur ne souhaite pas être relancé pour cette partie.', 409),
            NudgeRunParticipantOutcome::AlreadyNudged => new JsonResponse(['error' => ['code' => 'already_nudged', 'message' => 'Déjà relancé dans les dernières 24 h.', 'details' => []], 'data' => ['lastNudgedAt' => $at, 'hoursAgo' => $result->hoursAgo]], 429),
        };
    }

    #[Route('/api/v1/runs/{runId}/nudges/mute', name: 'api_runs_nudges_mute', methods: ['PUT'])]
    public function mute(Request $request, string $runId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $payload = json_decode($request->getContent() ?: '{}', true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $payload = null;
        }
        $muted = is_array($payload) ? ($payload['muted'] ?? null) : null;
        if (!is_bool($muted)) {
            return $this->apiAccessGuard->errorResponse('invalid_payload', 'Le champ muted est attendu.', 422);
        }

        if (!$this->nudge->mute($runId, $user->getId(), $muted)) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Partie introuvable.', 404);
        }

        return new JsonResponse(['data' => ['muted' => $muted]]);
    }
}
