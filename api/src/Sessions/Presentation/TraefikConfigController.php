<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\TraefikConfigBuilder;
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

        return new JsonResponse($this->traefikConfigBuilder->build());
    }
}
