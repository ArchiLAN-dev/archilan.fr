<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Presentation;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\WeeklyRuns\Application\WeeklyRunEntriesQueryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class WeeklyRunEntriesController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private WeeklyRunEntriesQueryInterface $entriesQuery,
    ) {
    }

    #[Route('/api/v1/weekly-runs/{weeklyRunId}/entries', name: 'api_weekly_runs_entries_list', methods: ['GET'])]
    public function list(Request $request, string $weeklyRunId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireMember($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $entries = $this->entriesQuery->findByRunId($weeklyRunId);

        if (null === $entries) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Run introuvable.', 404);
        }

        return new JsonResponse(['data' => $entries]);
    }
}
