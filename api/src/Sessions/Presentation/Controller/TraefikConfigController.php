<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Sessions\Application\Support\TraefikConfigBuilder;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class TraefikConfigController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private TraefikConfigBuilder $traefikConfigBuilder,
        private string $traefikToken,
    ) {
    }

    #[Route('/api/v1/internal/traefik', methods: ['GET'])]
    public function config(Request $request): JsonResponse
    {
        $provided = $request->headers->get('x-traefik-token', '');

        if ('' === $this->traefikToken || $provided !== $this->traefikToken) {
            return $this->apiAccessGuard->errorResponse('unauthorized', 'Token Traefik invalide.', 401);
        }

        // Traefik's HTTP provider decodes this body as YAML, and YAML 1.1 rejects the "\/" that PHP writes for
        // "/" by default ("found unknown escape character"): Traefik would keep its last valid configuration and
        // route no newly launched run. Slashes stay plain.
        $response = new JsonResponse();
        $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_UNESCAPED_SLASHES);

        return $response->setData($this->traefikConfigBuilder->build());
    }
}
