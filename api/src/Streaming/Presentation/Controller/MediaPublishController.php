<?php

declare(strict_types=1);

namespace App\Streaming\Presentation\Controller;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use App\Streaming\Application\Service\MediaPublishAccess;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Story 47.5: a registrant's key and addresses to publish their screen to the LAN media server. */
final readonly class MediaPublishController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private MediaPublishAccess $access,
    ) {
    }

    #[Route('/api/v1/registrations/{registrationId}/media-publish', name: 'api_registrations_media_publish', methods: ['GET'])]
    public function show(Request $request, string $registrationId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => $this->access->forOwner($registrationId, $user->getId()), 'meta' => []]);
    }

    #[Route('/api/v1/registrations/{registrationId}/media-publish/regenerate', name: 'api_registrations_media_publish_regenerate', methods: ['POST'])]
    public function regenerate(Request $request, string $registrationId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => $this->access->regenerate($registrationId, $user->getId()), 'meta' => []]);
    }
}
