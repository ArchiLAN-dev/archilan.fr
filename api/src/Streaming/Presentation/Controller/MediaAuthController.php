<?php

declare(strict_types=1);

namespace App\Streaming\Presentation\Controller;

use App\Streaming\Application\Service\MediaPublishSpikeGate;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 47.4 (spike): the `authHTTPAddress` of the LAN MediaMTX server. 2xx lets the action through, anything else
 * refuses it; the refusal says nothing about why. 404 while MEDIA_AUTH_SPIKE_KEY is empty.
 */
final readonly class MediaAuthController
{
    public function __construct(private MediaPublishSpikeGate $gate)
    {
    }

    #[Route('/api/v1/media-auth', name: 'api_media_auth', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        if (!$this->gate->isEnabled()) {
            return new JsonResponse(['error' => ['code' => 'not_found', 'message' => 'Introuvable.']], 404);
        }

        try {
            $body = json_decode($request->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $body = null;
        }

        if (!is_array($body) || !$this->gate->allows($body)) {
            return new JsonResponse(['error' => ['code' => 'unauthorized', 'message' => 'Refusé.']], 401);
        }

        return new JsonResponse(['data' => ['allowed' => true]]);
    }
}
