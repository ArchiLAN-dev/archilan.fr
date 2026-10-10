<?php

declare(strict_types=1);

namespace App\Community\Presentation\Controller;

use App\Community\Application\Service\FriendGroupService;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A member's private groups of friends (story 43.13). Every write answers with the groups as they now stand.
 */
final readonly class FriendGroupController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private FriendGroupService $groups,
    ) {
    }

    #[Route('/api/v1/community/friend-groups', name: 'api_community_friend_groups', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => $this->groups->groupsOf($user->getId())]);
    }

    #[Route('/api/v1/community/friend-groups', name: 'api_community_friend_groups_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return $this->answer($this->groups->create($user->getId(), self::name($request)), 201);
    }

    #[Route('/api/v1/community/friend-groups/{groupId}', name: 'api_community_friend_groups_rename', methods: ['PATCH'])]
    public function rename(Request $request, string $groupId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return $this->answer($this->groups->rename($user->getId(), $groupId, self::name($request)));
    }

    #[Route('/api/v1/community/friend-groups/{groupId}', name: 'api_community_friend_groups_delete', methods: ['DELETE'])]
    public function delete(Request $request, string $groupId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return $this->answer($this->groups->delete($user->getId(), $groupId));
    }

    #[Route('/api/v1/community/friend-groups/{groupId}/members/{userId}', name: 'api_community_friend_groups_add', methods: ['PUT'])]
    public function addMember(Request $request, string $groupId, string $userId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return $this->answer($this->groups->addMember($user->getId(), $groupId, $userId));
    }

    #[Route('/api/v1/community/friend-groups/{groupId}/members/{userId}', name: 'api_community_friend_groups_remove', methods: ['DELETE'])]
    public function removeMember(Request $request, string $groupId, string $userId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return $this->answer($this->groups->removeMember($user->getId(), $groupId, $userId));
    }

    /**
     * @param array{outcome: string, groups: list<array{id: string, name: string, memberIds: list<string>}>} $result
     */
    private function answer(array $result, int $okStatus = 200): JsonResponse
    {
        return match ($result['outcome']) {
            FriendGroupService::OK => new JsonResponse(['data' => $result['groups']], $okStatus),
            FriendGroupService::NOT_FOUND => $this->apiAccessGuard->errorResponse('not_found', 'Groupe introuvable.', 404),
            FriendGroupService::INVALID_NAME => $this->apiAccessGuard->errorResponse('invalid_name', sprintf('Le nom du groupe fait de 1 à %d caractères.', FriendGroupService::MAX_NAME_LENGTH), 422),
            FriendGroupService::GROUP_LIMIT => $this->apiAccessGuard->errorResponse('group_limit', sprintf('Pas plus de %d groupes.', FriendGroupService::MAX_GROUPS), 422),
            FriendGroupService::MEMBER_LIMIT => $this->apiAccessGuard->errorResponse('member_limit', sprintf('Pas plus de %d amis par groupe.', FriendGroupService::MAX_MEMBERS), 422),
            default => $this->apiAccessGuard->errorResponse('not_friend', 'Seuls tes amis entrent dans un groupe.', 422),
        };
    }

    private static function name(Request $request): string
    {
        try {
            $payload = json_decode($request->getContent() ?: '{}', true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }
        $name = is_array($payload) ? ($payload['name'] ?? null) : null;

        return is_string($name) ? $name : '';
    }
}
