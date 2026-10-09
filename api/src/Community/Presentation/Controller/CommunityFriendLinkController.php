<?php

declare(strict_types=1);

namespace App\Community\Presentation\Controller;

use App\Community\Application\Service\FriendLinkService;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Mon lien d'ami » (story 43.3): the member's own link, its regeneration, and the page a scanned QR code opens.
 */
final readonly class CommunityFriendLinkController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private FriendLinkService $friendLinks,
    ) {
    }

    #[Route('/api/v1/community/friend-link', name: 'api_community_friend_link', methods: ['GET'])]
    public function mine(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => ['code' => $this->friendLinks->codeFor($user->getId())]]);
    }

    #[Route('/api/v1/community/friend-link/regenerate', name: 'api_community_friend_link_regenerate', methods: ['POST'])]
    public function regenerate(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => ['code' => $this->friendLinks->regenerate($user->getId())]]);
    }

    #[Route('/api/v1/community/friend-link/{code}', name: 'api_community_friend_link_open', methods: ['GET'])]
    public function open(Request $request, string $code): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $view = $this->friendLinks->open($user->getId(), $code);
        if (null === $view) {
            return $this->invalid();
        }

        return new JsonResponse(['data' => $view]);
    }

    #[Route('/api/v1/community/friend-link/{code}/add', name: 'api_community_friend_link_add', methods: ['POST'])]
    public function add(Request $request, string $code): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $relationship = $this->friendLinks->add($user->getId(), $code);
        if (null === $relationship) {
            return $this->invalid();
        }
        if ('self' === $relationship['state']) {
            return $this->apiAccessGuard->errorResponse('self', 'C\'est ton propre lien d\'ami.', 422);
        }

        return new JsonResponse(['data' => $relationship]);
    }

    private function invalid(): JsonResponse
    {
        return $this->apiAccessGuard->errorResponse('friend_link_invalid', 'Ce lien d\'ami n\'est pas valide.', 404);
    }
}
