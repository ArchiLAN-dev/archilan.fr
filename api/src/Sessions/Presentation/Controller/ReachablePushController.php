<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Shared\Application\Support\ReachabilityForPlayer;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ReachablePushController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private HubInterface $mercureHub,
        private ReachabilityForPlayer $reachability,
        private string $centralApiSecret,
    ) {
    }

    /**
     * Story 27.13: the bridge pushes the raw result, which names the item of every location. It is published
     * three times - raw for admins, and the player's view with and without the spheres - and each reader's
     * subscribe token names the one it may read.
     */
    #[Route('/api/v1/internal/sessions/{sessionId}/slots/{slotIndex}/reachable-push', methods: ['POST'])]
    public function push(Request $request, string $sessionId, int $slotIndex): JsonResponse
    {
        $provided = $request->headers->get('x-internal-secret', '');

        if ('' === $this->centralApiSecret || $provided !== $this->centralApiSecret) {
            return $this->apiAccessGuard->errorResponse('unauthorized', 'Secret invalide.', 401);
        }

        $payload = $request->toArray();
        $variants = [
            $this->reachability->topic($sessionId, $slotIndex, true, true) => $payload,
            $this->reachability->topic($sessionId, $slotIndex, false, true) => $this->reachability->forPlayer($payload, true),
            $this->reachability->topic($sessionId, $slotIndex, false, false) => $this->reachability->forPlayer($payload, false),
        ];

        foreach ($variants as $topic => $data) {
            try {
                $this->mercureHub->publish(new Update($topic, json_encode($data, \JSON_THROW_ON_ERROR), true));
            } catch (\Throwable) {
                // Non-fatal - SSE clients will receive the data on next recompute
            }
        }

        return new JsonResponse(['data' => ['ok' => true]]);
    }
}
