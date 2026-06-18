<?php

declare(strict_types=1);

namespace App\Community\Presentation;

use App\Community\Application\CommunityFeedQuery;
use App\Community\Application\CommunityUserDirectoryQueryInterface;
use App\Identity\Domain\User;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CommunityFeedController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private CommunityFeedQuery $feedQuery,
        private CommunityUserDirectoryQueryInterface $directory,
    ) {
    }

    #[Route('/api/v1/community/profiles/{slug}/activity', name: 'api_community_profile_activity', methods: ['GET'])]
    public function profileActivity(Request $request, string $slug): JsonResponse
    {
        $viewer = $this->apiAccessGuard->optionalUser($request);
        $viewerId = $viewer instanceof User ? $viewer->getId() : null;

        $actorId = $this->directory->userIdForSlug($slug);
        if (null === $actorId) {
            return $this->apiAccessGuard->errorResponse('player_not_found', 'Joueur introuvable.', 404);
        }

        return new JsonResponse(['data' => $this->feedQuery->forActor($actorId, $viewerId, $this->limit($request), $this->before($request))]);
    }

    #[Route('/api/v1/community/feed', name: 'api_community_feed', methods: ['GET'])]
    public function feed(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => $this->feedQuery->feed($user->getId(), $this->limit($request), $this->before($request))]);
    }

    private function limit(Request $request): int
    {
        $limit = $request->query->get('limit');

        return is_numeric($limit) ? (int) $limit : 0;
    }

    private function before(Request $request): ?\DateTimeImmutable
    {
        $before = $request->query->get('before');
        if (!is_string($before) || '' === $before) {
            return null;
        }

        try {
            return new \DateTimeImmutable($before);
        } catch (\Exception) {
            return null;
        }
    }
}
