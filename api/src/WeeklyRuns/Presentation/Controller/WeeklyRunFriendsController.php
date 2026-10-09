<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Presentation\Controller;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\WeeklyRuns\Application\Query\WeeklyRunFriendsQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Tes amis cette semaine » (story 43.8), next to the weekly run's leaderboard.
 */
final readonly class WeeklyRunFriendsController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private WeeklyRunFriendsQuery $friends,
    ) {
    }

    #[Route('/api/v1/weekly-runs/{weeklyRunId}/friends', name: 'api_weekly_runs_friends', methods: ['GET'])]
    public function __invoke(Request $request, string $weeklyRunId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => $this->friends->forViewer($weeklyRunId, $user->getId())]);
    }
}
