<?php

declare(strict_types=1);

namespace App\Community\Presentation\Controller;

use App\Community\Application\Query\SharedHistoryQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Vous avez joué ensemble » (story 43.9), loaded by the profile page client-side (its SSR is anonymous).
 */
final readonly class CommunitySharedHistoryController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SharedHistoryQuery $sharedHistory,
    ) {
    }

    #[Route('/api/v1/community/profiles/{slug}/shared-history', name: 'api_community_shared_history', methods: ['GET'])]
    public function __invoke(Request $request, string $slug): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => $this->sharedHistory->between($user->getId(), $slug)]);
    }
}
