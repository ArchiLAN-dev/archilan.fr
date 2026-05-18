<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Presentation;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\WeeklyRuns\Application\LaunchWeeklyEntry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class WeeklyRunLaunchController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private LaunchWeeklyEntry $launchWeeklyEntry,
    ) {
    }

    #[Route('/api/v1/weekly-runs/{weeklyRunId}/entries/{entryId}/launch', name: 'api_weekly_runs_launch', methods: ['POST'])]
    public function __invoke(Request $request, string $weeklyRunId, string $entryId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireMember($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $data = $this->launchWeeklyEntry->execute($weeklyRunId, $entryId, $user->getId());
        } catch (\DomainException $e) {
            $message = $e->getMessage();
            if ('forbidden' === $message) {
                return new JsonResponse(['error' => $message], Response::HTTP_FORBIDDEN);
            }

            return new JsonResponse(['error' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (\RuntimeException) {
            return new JsonResponse(['error' => 'launch_failed'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        return new JsonResponse(['data' => $data], Response::HTTP_CREATED);
    }
}
