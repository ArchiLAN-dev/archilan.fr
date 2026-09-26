<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation\Controller;

use App\GameSelection\Application\Command\ApworldCandidateTriageOutcome;
use App\GameSelection\Application\Command\TriageApworldCandidate;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * An admin overrules the test of a game's apworld candidate (story 38.6): force it into service, or
 * rerun the test of a rejected one.
 */
final readonly class AdminApworldCandidateController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private TriageApworldCandidate $triage,
    ) {
    }

    #[Route('/api/v1/admin/games/{gameId}/apworld-candidate/promote', name: 'api_admin_game_apworld_candidate_promote', methods: ['POST'])]
    public function promote(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return $this->respond($this->triage->forcePromote($gameId, $admin->getId()));
    }

    #[Route('/api/v1/admin/games/{gameId}/apworld-candidate/retry', name: 'api_admin_game_apworld_candidate_retry', methods: ['POST'])]
    public function retry(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return $this->respond($this->triage->retry($gameId));
    }

    private function respond(ApworldCandidateTriageOutcome $outcome): JsonResponse
    {
        return match ($outcome) {
            ApworldCandidateTriageOutcome::Applied => new JsonResponse(['data' => ['outcome' => $outcome->value]]),
            ApworldCandidateTriageOutcome::NoCandidate => $this->apiAccessGuard->errorResponse('no_candidate', 'Aucune nouvelle version en test ou rejetée pour ce jeu.', 404),
            ApworldCandidateTriageOutcome::Forbidden => $this->apiAccessGuard->errorResponse('candidate_in_test', 'Cette version est encore en test : attends son verdict ou force-la.', 409),
            ApworldCandidateTriageOutcome::RunnerUnavailable => $this->apiAccessGuard->errorResponse('runner_unavailable', 'Le runner est indisponible, rien n\'a été changé. Réessaie plus tard.', 503),
        };
    }
}
