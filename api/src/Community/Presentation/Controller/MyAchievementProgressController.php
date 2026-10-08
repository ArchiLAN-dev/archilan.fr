<?php

declare(strict_types=1);

namespace App\Community\Presentation\Controller;

use App\Community\Application\Query\AchievementProgressQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 30.53: where the signed-in member stands on one of their achievements. Their own only: the numbers tell
 * their activity, which a profile shows to others as totals alone.
 */
final readonly class MyAchievementProgressController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AchievementProgressQuery $progress,
    ) {
    }

    #[Route('/api/v1/community/profile/achievements/{key}/progress', name: 'api_community_my_achievement_progress', methods: ['GET'])]
    public function progress(Request $request, string $key): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $progress = $this->progress->forMember($user->getId(), $key);

        return null === $progress
            ? $this->apiAccessGuard->errorResponse('not_found', 'Succès introuvable.', 404)
            : new JsonResponse(['data' => $progress]);
    }
}
