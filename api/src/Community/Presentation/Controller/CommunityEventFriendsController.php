<?php

declare(strict_types=1);

namespace App\Community\Presentation\Controller;

use App\Community\Application\Query\EventFriendsQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « N de tes amis participent » (story 43.4), on an event page and, grouped, on the list of upcoming events. The
 * grouped read lives under /community: /events/friends would be read as an event called « friends ».
 */
final readonly class CommunityEventFriendsController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private EventFriendsQuery $eventFriends,
    ) {
    }

    #[Route('/api/v1/events/{eventId}/friends', name: 'api_event_friends', methods: ['GET'])]
    public function forEvent(Request $request, string $eventId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => $this->eventFriends->forEvent($user->getId(), $eventId)]);
    }

    #[Route('/api/v1/community/event-friends', name: 'api_community_event_friends', methods: ['GET'])]
    public function forEvents(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $ids = $request->query->getString('ids');
        $eventIds = array_values(array_filter(explode(',', $ids), static fn (string $id): bool => '' !== trim($id)));

        return new JsonResponse(['data' => (object) $this->eventFriends->forEvents($user->getId(), array_map(trim(...), $eventIds))]);
    }
}
