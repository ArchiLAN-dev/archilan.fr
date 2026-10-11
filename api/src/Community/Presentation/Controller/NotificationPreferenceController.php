<?php

declare(strict_types=1);

namespace App\Community\Presentation\Controller;

use App\Community\Application\Service\NotificationPreferenceService;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 43.11b: where each configurable type of notification reaches the member.
 */
final readonly class NotificationPreferenceController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private NotificationPreferenceService $preferences,
    ) {
    }

    #[Route('/api/v1/community/notification-preferences', name: 'api_community_notification_preferences', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => $this->preferences->forUser($user->getId())]);
    }

    #[Route('/api/v1/community/notification-preferences/{type}', name: 'api_community_notification_preference_choose', methods: ['PUT'])]
    public function choose(Request $request, string $type): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        try {
            $payload = json_decode($request->getContent() ?: '{}', true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $payload = null;
        }
        $channel = is_array($payload) && is_string($payload['channel'] ?? null) ? $payload['channel'] : '';

        $settings = $this->preferences->choose($user->getId(), $type, $channel);
        if (null === $settings) {
            return $this->apiAccessGuard->errorResponse('invalid_preference', 'Réglage inconnu.', 422);
        }

        return new JsonResponse(['data' => $settings]);
    }
}
