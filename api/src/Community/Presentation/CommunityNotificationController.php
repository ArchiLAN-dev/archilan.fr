<?php

declare(strict_types=1);

namespace App\Community\Presentation;

use App\Community\Application\NotificationService;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CommunityNotificationController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private NotificationService $notifications,
    ) {
    }

    #[Route('/api/v1/community/notifications', name: 'api_community_notifications', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $limit = $request->query->getInt('limit', 30);
        $result = $this->notifications->recent($user->getId(), $limit);

        return new JsonResponse(['data' => $result['items'], 'meta' => ['unreadCount' => $result['unreadCount']]]);
    }

    #[Route('/api/v1/community/notifications/{id}/read', name: 'api_community_notification_read', methods: ['POST'])]
    public function read(Request $request, string $id): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return match ($this->notifications->markRead($id, $user->getId())) {
            'ok' => new JsonResponse(null, 204),
            'forbidden' => $this->apiAccessGuard->errorResponse('forbidden', 'Notification non accessible.', 403),
            default => $this->apiAccessGuard->errorResponse('not_found', 'Notification introuvable.', 404),
        };
    }

    #[Route('/api/v1/community/notifications/read-all', name: 'api_community_notifications_read_all', methods: ['POST'])]
    public function readAll(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $this->notifications->markAllRead($user->getId());

        return new JsonResponse(null, 204);
    }
}
