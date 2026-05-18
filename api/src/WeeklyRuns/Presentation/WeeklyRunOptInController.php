<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Presentation;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\WeeklyRuns\Application\OptInToWeeklyRun;
use App\WeeklyRuns\Application\WithdrawFromWeeklyRun;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class WeeklyRunOptInController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private OptInToWeeklyRun $optInToWeeklyRun,
        private WithdrawFromWeeklyRun $withdrawFromWeeklyRun,
    ) {
    }

    #[Route('/api/v1/weekly-runs/{weeklyRunId}/entries', name: 'api_weekly_runs_opt_in', methods: ['POST'])]
    public function optIn(Request $request, string $weeklyRunId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireMember($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $data = $this->optInToWeeklyRun->execute($weeklyRunId, $user->getId());
        } catch (\DomainException $e) {
            if ('entry_conflict' === $e->getMessage()) {
                return new JsonResponse(['error' => 'entry_conflict'], Response::HTTP_CONFLICT);
            }

            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['data' => $data], Response::HTTP_CREATED);
    }

    #[Route('/api/v1/weekly-runs/{weeklyRunId}/entries/{entryId}', name: 'api_weekly_runs_withdraw', methods: ['DELETE'])]
    public function withdraw(Request $request, string $weeklyRunId, string $entryId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireMember($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $this->withdrawFromWeeklyRun->execute($weeklyRunId, $entryId, $user->getId());
        } catch (\DomainException $e) {
            $message = $e->getMessage();
            if ('forbidden' === $message) {
                return new JsonResponse(['error' => $message], Response::HTTP_FORBIDDEN);
            }

            return new JsonResponse(['error' => $message], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
