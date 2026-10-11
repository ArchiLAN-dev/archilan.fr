<?php

declare(strict_types=1);

namespace App\Community\Presentation\Controller;

use App\Community\Application\Query\FriendsNowQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Mes amis en ce moment » (story 43.5), polled by the card on /compte and /communaute.
 */
final readonly class CommunityFriendsNowController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private FriendsNowQuery $friendsNow,
    ) {
    }

    #[Route('/api/v1/community/friends/now', name: 'api_community_friends_now', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => $this->friendsNow->forViewer($user->getId())]);
    }
}
