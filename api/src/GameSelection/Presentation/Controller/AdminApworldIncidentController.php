<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation\Controller;

use App\GameSelection\Application\Command\ApworldIncidentTriageOutcome;
use App\GameSelection\Application\Command\TriageApworldIncident;
use App\GameSelection\Application\Query\ApworldIncidentListItem;
use App\GameSelection\Application\Query\ApworldIncidentListQueryInterface;
use App\GameSelection\Application\Query\ApworldIncidentListScope;
use App\GameSelection\Application\Query\CatalogSweepProgress;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The admin API behind the apworld health page (story 38.3): list the incidents, count them for the
 * menu badge, and take, resolve or ignore one.
 */
final readonly class AdminApworldIncidentController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private ApworldIncidentListQueryInterface $incidentList,
        private TriageApworldIncident $triage,
        private CatalogSweepProgress $sweepProgress,
    ) {
    }

    #[Route('/api/v1/admin/apworld-incidents', name: 'api_admin_apworld_incidents_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $scope = ApworldIncidentListScope::tryFrom($request->query->getString('status', 'active')) ?? ApworldIncidentListScope::Active;
        $gameId = $request->query->getString('gameId');

        $items = $this->incidentList->list($scope, '' === $gameId ? null : $gameId);

        return new JsonResponse([
            'data' => array_map(static fn (ApworldIncidentListItem $item): array => $item->toArray(), $items),
            'meta' => ['status' => $scope->value, 'count' => \count($items)],
        ]);
    }

    #[Route('/api/v1/admin/apworld-incidents/summary', name: 'api_admin_apworld_incidents_summary', methods: ['GET'])]
    public function summary(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $summary = $this->incidentList->summary();

        return new JsonResponse(['data' => ['active' => $summary->active, 'unacknowledged' => $summary->unacknowledged]]);
    }

    /**
     * Story 38.9: how far the rolling test has come on the image in use. Null data when the runner
     * does not say which image runs.
     */
    #[Route('/api/v1/admin/apworld-incidents/sweep-progress', name: 'api_admin_apworld_incidents_sweep_progress', methods: ['GET'])]
    public function sweepProgress(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $progress = $this->sweepProgress->progress();

        return new JsonResponse(['data' => null === $progress ? null : [
            'currentImage' => $progress->currentImage,
            'testedOnCurrentImage' => $progress->testedOnCurrentImage,
            'total' => $progress->total,
        ]]);
    }

    #[Route('/api/v1/admin/apworld-incidents/{incidentId}/acknowledge', name: 'api_admin_apworld_incidents_acknowledge', methods: ['POST'])]
    public function acknowledge(Request $request, string $incidentId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return $this->respond($this->triage->acknowledge($incidentId, $admin->getId()));
    }

    #[Route('/api/v1/admin/apworld-incidents/{incidentId}/resolve', name: 'api_admin_apworld_incidents_resolve', methods: ['POST'])]
    public function resolve(Request $request, string $incidentId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return $this->respond($this->triage->resolve($incidentId, $admin->getId()));
    }

    #[Route('/api/v1/admin/apworld-incidents/{incidentId}/ignore', name: 'api_admin_apworld_incidents_ignore', methods: ['POST'])]
    public function ignore(Request $request, string $incidentId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return $this->respond($this->triage->ignore($incidentId, $admin->getId()));
    }

    private function respond(ApworldIncidentTriageOutcome $outcome): JsonResponse
    {
        return match ($outcome) {
            ApworldIncidentTriageOutcome::Applied => new JsonResponse(['data' => ['outcome' => $outcome->value]]),
            ApworldIncidentTriageOutcome::NotFound => $this->apiAccessGuard->errorResponse('not_found', 'Incident introuvable.', 404),
            ApworldIncidentTriageOutcome::Forbidden => $this->apiAccessGuard->errorResponse(
                'incident_closed',
                'Cet incident est déjà clos : il ne peut plus être pris en charge, résolu ni ignoré.',
                409,
            ),
        };
    }
}
