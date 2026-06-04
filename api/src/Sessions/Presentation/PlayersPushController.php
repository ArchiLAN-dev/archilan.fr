<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PlayersPushController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private HubInterface $mercureHub,
        private string $centralApiSecret,
    ) {
    }

    #[Route('/api/v1/internal/sessions/{sessionId}/players-push', methods: ['POST'])]
    public function push(Request $request, string $sessionId): JsonResponse
    {
        $provided = $request->headers->get('x-internal-secret', '');

        if ('' === $this->centralApiSecret || $provided !== $this->centralApiSecret) {
            return $this->apiAccessGuard->errorResponse('unauthorized', 'Secret invalide.', 401);
        }

        $payload = $request->toArray();
        $topic = sprintf('runs/%s/players', $sessionId);

        try {
            $this->mercureHub->publish(new Update(
                $topic,
                json_encode($payload, \JSON_THROW_ON_ERROR),
                true,
            ));
        } catch (\Throwable) {
            // Non-fatal — SSE clients will get the data on next state_changed broadcast
        }

        return new JsonResponse(['data' => ['ok' => true]]);
    }
}
