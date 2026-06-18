<?php

declare(strict_types=1);

namespace App\Community\Presentation;

use App\Community\Application\CommunityUserDirectoryQueryInterface;
use App\Community\Application\FriendshipService;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CommunityFriendshipController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private FriendshipService $friendships,
        private CommunityUserDirectoryQueryInterface $directory,
    ) {
    }

    #[Route('/api/v1/community/friends', name: 'api_community_friends', methods: ['GET'])]
    public function friends(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => $this->friendships->friends($user->getId())]);
    }

    #[Route('/api/v1/community/profiles/{slug}/relationship', name: 'api_community_relationship', methods: ['GET'])]
    public function relationship(Request $request, string $slug): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $targetId = $this->directory->userIdForSlug($slug);
        if (null === $targetId) {
            return $this->apiAccessGuard->errorResponse('player_not_found', 'Joueur introuvable.', 404);
        }

        return new JsonResponse(['data' => $this->friendships->relationship($user->getId(), $targetId)]);
    }

    #[Route('/api/v1/community/profiles/{slug}/friend-request', name: 'api_community_friend_request', methods: ['POST'])]
    public function request(Request $request, string $slug): JsonResponse
    {
        return $this->actOnTarget($request, $slug, fn (string $uid, string $tid): string => $this->friendships->requestFriend($uid, $tid));
    }

    #[Route('/api/v1/community/profiles/{slug}/friendship', name: 'api_community_friendship_remove', methods: ['DELETE'])]
    public function remove(Request $request, string $slug): JsonResponse
    {
        return $this->actOnTarget($request, $slug, function (string $uid, string $tid): string {
            $this->friendships->removeFriendship($uid, $tid);

            return 'ok';
        });
    }

    #[Route('/api/v1/community/profiles/{slug}/block', name: 'api_community_block', methods: ['POST'])]
    public function block(Request $request, string $slug): JsonResponse
    {
        return $this->actOnTarget($request, $slug, fn (string $uid, string $tid): string => $this->friendships->block($uid, $tid));
    }

    #[Route('/api/v1/community/profiles/{slug}/block', name: 'api_community_unblock', methods: ['DELETE'])]
    public function unblock(Request $request, string $slug): JsonResponse
    {
        return $this->actOnTarget($request, $slug, function (string $uid, string $tid): string {
            $this->friendships->unblock($uid, $tid);

            return 'ok';
        });
    }

    #[Route('/api/v1/community/friendships/{id}/accept', name: 'api_community_friendship_accept', methods: ['POST'])]
    public function accept(Request $request, string $id): JsonResponse
    {
        return $this->respondToRequest($request, $id, fn (string $uid): string => $this->friendships->accept($uid, $id));
    }

    #[Route('/api/v1/community/friendships/{id}/decline', name: 'api_community_friendship_decline', methods: ['POST'])]
    public function decline(Request $request, string $id): JsonResponse
    {
        return $this->respondToRequest($request, $id, fn (string $uid): string => $this->friendships->decline($uid, $id));
    }

    /**
     * @param callable(string, string): string $action
     */
    private function actOnTarget(Request $request, string $slug, callable $action): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $targetId = $this->directory->userIdForSlug($slug);
        if (null === $targetId) {
            return $this->apiAccessGuard->errorResponse('player_not_found', 'Joueur introuvable.', 404);
        }

        $status = $action($user->getId(), $targetId);
        if ('blocked' === $status) {
            return $this->apiAccessGuard->errorResponse('blocked', 'Action impossible : un blocage est en place.', 409);
        }
        if ('self' === $status) {
            return $this->apiAccessGuard->errorResponse('self', 'Action impossible sur soi-même.', 422);
        }

        return new JsonResponse(['data' => $this->friendships->relationship($user->getId(), $targetId)]);
    }

    /**
     * @param callable(string): string $action
     */
    private function respondToRequest(Request $request, string $id, callable $action): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        if ('ok' !== $action($user->getId())) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Demande introuvable.', 404);
        }

        return new JsonResponse(null, 204);
    }
}
