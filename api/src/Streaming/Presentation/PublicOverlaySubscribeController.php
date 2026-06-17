<?php

declare(strict_types=1);

namespace App\Streaming\Presentation;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Streaming\Application\OverlaySubscribeQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public (unauthenticated) endpoint an OBS browser source calls to get a short-lived, subscribe-only
 * Mercure JWT (feed + players topics) for a session. Overlays are read-only and meant to be shown on
 * stream, so the URL is permanent and tokenless (keyed on the session id); an unknown session yields a 404.
 */
final readonly class PublicOverlaySubscribeController
{
    private const TOKEN_TTL_SECONDS = 3600;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private OverlaySubscribeQuery $overlaySubscribeQuery,
        private HubInterface $mercureHub,
    ) {
    }

    #[Route('/api/v1/public/overlay/{runId}/subscribe', methods: ['GET'])]
    public function subscribe(string $runId): JsonResponse
    {
        $topics = $this->overlaySubscribeQuery->resolveTopics($runId);
        if (null === $topics) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Overlay indisponible.', 404);
        }

        $factory = $this->mercureHub->getFactory();
        if (null === $factory) {
            return $this->apiAccessGuard->errorResponse('service_unavailable', 'Service de token non disponible.', 503);
        }

        $token = $factory->create(
            subscribe: $topics,
            additionalClaims: ['exp' => new \DateTimeImmutable('+'.self::TOKEN_TTL_SECONDS.' seconds')],
        );

        return new JsonResponse([
            'data' => [
                'token' => $token,
                'hubUrl' => $this->mercureHub->getPublicUrl(),
                'topics' => $topics,
            ],
        ]);
    }
}
