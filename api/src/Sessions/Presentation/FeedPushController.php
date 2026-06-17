<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives a single feed event pushed by the bridge and republishes it on the Mercure topic
 * runs/{id}/feed (mirrors PlayersPushController). Without this the bridge feed only reaches local WS
 * clients and the GET /feed snapshot - never the live Mercure stream the frontend EventFeed and OBS
 * overlays subscribe to.
 *
 * Anti-corruption mapping: the bridge speaks AP-native FeedEventType (`item_sent`); the app's feed
 * vocabulary uses `item-received`. We normalize that single value here at the bridge -> app boundary so
 * the generic bridge keeps its naming and every app-side consumer keeps the one it already expects.
 */
final readonly class FeedPushController
{
    private const TYPE_MAP = [
        'item_sent' => 'item-received',
    ];

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private HubInterface $mercureHub,
        private string $centralApiSecret,
    ) {
    }

    #[Route('/api/v1/internal/sessions/{sessionId}/feed-push', methods: ['POST'])]
    public function push(Request $request, string $sessionId): JsonResponse
    {
        $provided = $request->headers->get('x-internal-secret', '');

        if ('' === $this->centralApiSecret || $provided !== $this->centralApiSecret) {
            return $this->apiAccessGuard->errorResponse('unauthorized', 'Secret invalide.', 401);
        }

        $event = $request->toArray();

        $type = $event['type'] ?? null;
        if (is_string($type) && isset(self::TYPE_MAP[$type])) {
            $event['type'] = self::TYPE_MAP[$type];
        }

        $topic = sprintf('runs/%s/feed', $sessionId);

        try {
            $this->mercureHub->publish(new Update(
                $topic,
                json_encode($event, \JSON_THROW_ON_ERROR),
                true,
            ));
        } catch (\Throwable) {
            // Non-fatal - SSE clients reconnect and the bridge re-pushes subsequent events.
        }

        return new JsonResponse(['data' => ['ok' => true]]);
    }
}
