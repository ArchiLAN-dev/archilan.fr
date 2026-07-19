<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation\Controller;

use App\GameSelection\Application\Command\ModerateGameTutorialContribution;
use App\GameSelection\Application\Query\AdminGameContributionsQueryInterface;
use App\GameSelection\Application\Query\ContributionQueryFilters;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminGameContributionController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminGameContributionsQueryInterface $query,
        private ModerateGameTutorialContribution $moderate,
    ) {
    }

    #[Route('/api/v1/admin/game-contributions', name: 'api_admin_game_contributions_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $filters = ContributionQueryFilters::fromRaw(
            $this->queryString($request, 'status'),
            $this->queryString($request, 'target'),
            $this->queryString($request, 'sort'),
            $this->queryString($request, 'q'),
        );

        return new JsonResponse([
            'data' => $this->query->list($filters),
            'meta' => ['count' => $this->query->pendingCount()],
        ]);
    }

    private function queryString(Request $request, string $key): ?string
    {
        $value = $request->query->get($key);

        return is_string($value) ? $value : null;
    }

    #[Route('/api/v1/admin/game-contributions/{id}/approve', name: 'api_admin_game_contributions_approve', methods: ['POST'])]
    public function approve(Request $request, string $id): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = json_decode($request->getContent(), true);
        $payload = is_array($payload) ? $payload : [];
        $overrideSteps = is_array($payload['steps'] ?? null) ? $payload['steps'] : null;

        // Failures (missing, already moderated, invalid steps) are thrown as typed ApplicationFailures
        // and mapped to HTTP by ApplicationFailureListener (epic 35).
        $this->moderate->approve($id, $admin->getId(), $overrideSteps);

        return new JsonResponse(['meta' => ['message' => 'Contribution appliquée.']]);
    }

    #[Route('/api/v1/admin/game-contributions/{id}/reject', name: 'api_admin_game_contributions_reject', methods: ['POST'])]
    public function reject(Request $request, string $id): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = json_decode($request->getContent(), true);
        $payload = is_array($payload) ? $payload : [];
        $reason = is_string($payload['reason'] ?? null) ? $payload['reason'] : '';

        $this->moderate->reject($id, $admin->getId(), $reason);

        return new JsonResponse(['meta' => ['message' => 'Contribution refusée.']]);
    }
}
