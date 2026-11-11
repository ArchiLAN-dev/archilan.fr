<?php

declare(strict_types=1);

namespace App\Streaming\Presentation\Controller;

use App\Streaming\Application\Service\MediaPublishAuthorization;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 47.5: the `authHTTPAddress` of the LAN media server (MediaMTX). 2xx lets the publication through, anything
 * else refuses it; the refusal says nothing about why (MediaMTX writes it in its logs).
 */
final readonly class MediaAuthController
{
    public function __construct(private MediaPublishAuthorization $authorization)
    {
    }

    #[Route('/api/v1/media-auth', name: 'api_media_auth', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        try {
            $body = json_decode($request->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $body = null;
        }

        if (!is_array($body) || !$this->authorization->allows($body)) {
            return new JsonResponse(['error' => ['code' => 'unauthorized', 'message' => 'Refusé.']], 401);
        }

        return new JsonResponse(['data' => ['allowed' => true]]);
    }
}
