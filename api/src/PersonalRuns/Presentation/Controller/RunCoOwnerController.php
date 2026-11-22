<?php

declare(strict_types=1);

namespace App\PersonalRuns\Presentation\Controller;

use App\PersonalRuns\Application\Command\SetRunCoOwner;
use App\PersonalRuns\Application\Command\SetRunCoOwnerOutcome;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 16.27: the owner of a private run names or removes a co-organiser.
 */
final readonly class RunCoOwnerController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SetRunCoOwner $setCoOwner,
    ) {
    }

    #[Route('/api/v1/runs/{runId}/participants/{userId}/co-owner', name: 'api_runs_participant_co_owner', methods: ['PUT'], requirements: ['userId' => '[0-9a-f]{32}'])]
    public function set(Request $request, string $runId, string $userId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $body = json_decode($request->getContent(), true);
        $coOwner = is_array($body) ? ($body['coOwner'] ?? null) : null;
        if (!is_bool($coOwner)) {
            return $this->apiAccessGuard->errorResponse('validation_error', 'coOwner doit être un booléen.', 422);
        }

        return match ($this->setCoOwner->set($runId, $user->getId(), $userId, $coOwner)) {
            SetRunCoOwnerOutcome::Done => new JsonResponse(['data' => ['userId' => $userId, 'coOwner' => $coOwner]]),
            SetRunCoOwnerOutcome::RunNotFound => $this->apiAccessGuard->errorResponse('not_found', 'Partie introuvable.', 404),
            SetRunCoOwnerOutcome::Forbidden => $this->apiAccessGuard->errorResponse('forbidden', 'Seul le lanceur de la partie nomme un co-organisateur.', 403),
            SetRunCoOwnerOutcome::ParticipantNotFound => $this->apiAccessGuard->errorResponse('participant_not_found', 'Ce joueur ne participe pas à la partie.', 404),
            SetRunCoOwnerOutcome::IsOwner => $this->apiAccessGuard->errorResponse('is_owner', 'Le lanceur pilote déjà sa partie.', 422),
            SetRunCoOwnerOutcome::RunTerminal => $this->apiAccessGuard->errorResponse('run_terminal', 'Cette partie est terminée.', 409),
        };
    }
}
