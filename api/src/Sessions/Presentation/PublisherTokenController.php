<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\SessionQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PublisherTokenController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionQuery $sessionQuery,
        private HubInterface $mercureHub,
        private string $centralApiSecret,
    ) {
    }

    #[Route('/api/v1/internal/sessions/{runId}/publisher-token', methods: ['GET'])]
    public function token(Request $request, string $runId): JsonResponse
    {
        $provided = $request->headers->get('x-internal-secret', '');

        if ('' === $this->centralApiSecret || $provided !== $this->centralApiSecret) {
            return $this->apiAccessGuard->errorResponse('unauthorized', 'Secret invalide.', 401);
        }

        $session = $this->sessionQuery->findById($runId);

        if (null === $session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        $ttl = 3600;
        $expiresAt = new \DateTimeImmutable('+'.$ttl.' seconds');

        $factory = $this->mercureHub->getFactory();
        if (null === $factory) {
            return $this->apiAccessGuard->errorResponse('service_unavailable', 'Service de token non disponible.', 503);
        }

        $token = $factory->create(
            subscribe: [],
            publish: ['*'],
            additionalClaims: ['exp' => $expiresAt],
        );

        return new JsonResponse([
            'data' => [
                'token' => $token,
                'expires_at' => $expiresAt->format(\DateTimeInterface::ATOM),
            ],
        ]);
    }
}
