<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Sessions\Application\Command\BalanceDryGenerationOutcome;
use App\Sessions\Application\Command\RunBalanceDryGeneration;
use App\Sessions\Application\Query\BalanceReportQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 56.3: the dry generation of an event (admins) or a private run (its organisers), its balance report, and a
 * registered player's own lines of their event's report.
 */
final readonly class BalanceReportController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private RunBalanceDryGeneration $dryGeneration,
        private BalanceReportQuery $reports,
    ) {
    }

    #[Route('/api/v1/admin/events/{eventId}/balance', name: 'api_admin_events_balance', methods: ['GET'])]
    public function eventReport(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $report = $this->reports->forEvent($eventId);

        return null === $report
            ? $this->apiAccessGuard->errorResponse('not_found', 'Événement introuvable.', 404)
            : $this->noStore(['data' => $report]);
    }

    #[Route('/api/v1/admin/events/{eventId}/balance', name: 'api_admin_events_balance_generate', methods: ['POST'])]
    public function generateEvent(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return $this->outcome($this->dryGeneration->forEvent($eventId));
    }

    #[Route('/api/v1/runs/{runId}/balance', name: 'api_runs_balance', methods: ['GET'])]
    public function runReport(Request $request, string $runId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $result = $this->reports->forRun($runId, $user->getId(), in_array('ROLE_ADMIN', $user->getRoles(), true));
        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Partie introuvable.', 404);
        }
        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Seuls les organisateurs de la partie voient son équilibre.', 403);
        }

        return $this->noStore(['data' => $result['report'] ?? []]);
    }

    #[Route('/api/v1/runs/{runId}/balance', name: 'api_runs_balance_generate', methods: ['POST'])]
    public function generateRun(Request $request, string $runId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return $this->outcome($this->dryGeneration->forRun($runId, $user->getId(), in_array('ROLE_ADMIN', $user->getRoles(), true)));
    }

    #[Route('/api/v1/events/{eventId}/my-balance', name: 'api_events_my_balance', methods: ['GET'])]
    public function playerReport(Request $request, string $eventId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $report = $this->reports->forPlayer($eventId, $user->getId());

        return null === $report
            ? $this->apiAccessGuard->errorResponse('not_registered', 'Tu n\'es pas inscrit à cet événement.', 404)
            : $this->noStore(['data' => $report]);
    }

    private function outcome(BalanceDryGenerationOutcome $outcome): JsonResponse
    {
        return match ($outcome) {
            BalanceDryGenerationOutcome::Started => new JsonResponse(['data' => ['status' => 'generating']], 202),
            BalanceDryGenerationOutcome::NeedsFiles => new JsonResponse(['data' => ['status' => 'needs_files']], 200),
            BalanceDryGenerationOutcome::Failed => new JsonResponse(['data' => ['status' => 'failed']], 200),
            BalanceDryGenerationOutcome::NotFound => $this->apiAccessGuard->errorResponse('not_found', 'Introuvable.', 404),
            BalanceDryGenerationOutcome::Forbidden => $this->apiAccessGuard->errorResponse('forbidden', 'Seuls les organisateurs de la partie lancent une génération à blanc.', 403),
            BalanceDryGenerationOutcome::NoSlots => $this->apiAccessGuard->errorResponse('no_slots', 'Aucun slot à générer.', 422),
            BalanceDryGenerationOutcome::AlreadyGenerating => $this->apiAccessGuard->errorResponse('already_generating', 'Une génération à blanc est déjà en cours.', 409),
            BalanceDryGenerationOutcome::ImportedSeed => $this->apiAccessGuard->errorResponse('imported_seed', 'Une seed importée n\'est pas générée.', 422),
        };
    }

    /** @param array<string, mixed> $body */
    private function noStore(array $body): JsonResponse
    {
        $response = new JsonResponse($body);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
