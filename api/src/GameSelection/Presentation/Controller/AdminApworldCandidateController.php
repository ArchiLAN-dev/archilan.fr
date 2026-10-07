<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation\Controller;

use App\GameSelection\Application\Command\ApworldCandidateTriageOutcome;
use App\GameSelection\Application\Command\ApworldCandidateYamlTestOutcome;
use App\GameSelection\Application\Command\StartApworldCandidateYamlTest;
use App\GameSelection\Application\Command\TriageApworldCandidate;
use App\GameSelection\Application\Query\ApworldCandidateYamlTestQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * An admin overrules the test of a game's apworld candidate (story 38.6): force it into service, or
 * rerun the test of a rejected one. Story 38.14: put online a candidate held for approval, and test a
 * candidate with a pasted YAML before that.
 */
final readonly class AdminApworldCandidateController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private TriageApworldCandidate $triage,
        private StartApworldCandidateYamlTest $startYamlTest,
        private ApworldCandidateYamlTestQuery $yamlTests,
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

    #[Route('/api/v1/admin/games/{gameId}/apworld-candidate/approve', name: 'api_admin_game_apworld_candidate_approve', methods: ['POST'])]
    public function approve(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return $this->respond(
            $this->triage->approve($gameId, $admin->getId()),
            ['candidate_not_awaiting', 'Cette version n\'attend pas de validation : son test n\'a pas encore réussi.'],
        );
    }

    #[Route('/api/v1/admin/games/{gameId}/apworld-candidate/test-yaml', name: 'api_admin_game_apworld_candidate_test_yaml', methods: ['POST'])]
    public function startYamlTest(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $body = json_decode($request->getContent(), true);
        $yaml = is_array($body) && is_string($body['yaml'] ?? null) ? $body['yaml'] : '';
        $start = $this->startYamlTest->start($gameId, $yaml);

        return match ($start->outcome) {
            ApworldCandidateYamlTestOutcome::Started => new JsonResponse(['data' => ['jobId' => $start->jobId]], 202),
            ApworldCandidateYamlTestOutcome::InvalidYaml => $this->apiAccessGuard->errorResponse('validation_failed', 'Colle un YAML (100 Ko au plus).', 422, ['yaml' => ['Colle un YAML (100 Ko au plus).']]),
            ApworldCandidateYamlTestOutcome::NoCandidate => $this->apiAccessGuard->errorResponse('no_candidate', 'Aucune nouvelle version à tester pour ce jeu.', 404),
            ApworldCandidateYamlTestOutcome::RunnerUnavailable => $this->apiAccessGuard->errorResponse('runner_unavailable', 'Le runner est indisponible, le test n\'a pas été lancé. Réessaie plus tard.', 503),
        };
    }

    #[Route('/api/v1/admin/games/{gameId}/apworld-candidate/test-yaml/{jobId}', name: 'api_admin_game_apworld_candidate_test_yaml_result', methods: ['GET'])]
    public function yamlTestResult(Request $request, string $gameId, string $jobId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = $this->yamlTests->result($jobId);
        if (null === $result) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Test introuvable ou expiré : relance-le.', 404);
        }

        return new JsonResponse(['data' => $result]);
    }

    /**
     * @param array{string, string}|null $forbidden the code and message of a 409, when they differ from a retry's
     */
    private function respond(ApworldCandidateTriageOutcome $outcome, ?array $forbidden = null): JsonResponse
    {
        [$forbiddenCode, $forbiddenMessage] = $forbidden ?? ['candidate_in_test', 'Cette version est encore en test : attends son verdict ou force-la.'];

        return match ($outcome) {
            ApworldCandidateTriageOutcome::Applied => new JsonResponse(['data' => ['outcome' => $outcome->value]]),
            ApworldCandidateTriageOutcome::NoCandidate => $this->apiAccessGuard->errorResponse('no_candidate', 'Aucune nouvelle version en test ou rejetée pour ce jeu.', 404),
            ApworldCandidateTriageOutcome::Forbidden => $this->apiAccessGuard->errorResponse($forbiddenCode, $forbiddenMessage, 409),
            ApworldCandidateTriageOutcome::RunnerUnavailable => $this->apiAccessGuard->errorResponse('runner_unavailable', 'Le runner est indisponible, rien n\'a été changé. Réessaie plus tard.', 503),
        };
    }
}
