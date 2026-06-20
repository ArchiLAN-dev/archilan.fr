<?php

declare(strict_types=1);

namespace App\Streaming\Presentation;

use App\Sessions\Application\SessionQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Publishes a sample event on the session's overlay-only test channel (`runs/{id}/overlay-test`) so an
 * operator can verify a real OBS source reacts - end to end through Mercure, not just a client-side
 * demo. Player progression pages subscribe to `feed`/`players` only, never `overlay-test`, so these test
 * events are invisible to them. Same authorization as issuing the overlay token (admin / session owner).
 */
final readonly class OverlayTestController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionQuery $sessionQuery,
        private HubInterface $mercureHub,
    ) {
    }

    #[Route('/api/v1/sessions/{runId}/overlay-test', methods: ['POST'])]
    public function test(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $session = $this->sessionQuery->findById($runId);
        if (null === $session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        $isAdmin = in_array('ROLE_ADMIN', $user->getRoles(), true);
        if (!$isAdmin
            && !$this->sessionQuery->isUserAuthorizedForSession($user->getId(), $session['eventId'], $runId)
        ) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        $type = '';
        $slot = '';
        try {
            $body = $request->toArray();
            $type = is_string($body['type'] ?? null) ? $body['type'] : '';
            $slot = is_string($body['slot'] ?? null) ? $body['slot'] : '';
        } catch (\Throwable) {
            $type = '';
        }

        try {
            $this->mercureHub->publish(new Update(
                sprintf('runs/%s/overlay-test', $runId),
                json_encode($this->samplePayload($type, $slot), \JSON_THROW_ON_ERROR),
                true,
            ));
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('service_unavailable', 'Publication impossible.', 503);
        }

        return new JsonResponse(['data' => ['ok' => true]]);
    }

    /**
     * Builds a sample event of the requested type, targeting `$slot` (the player the event concerns) so
     * it honors the per-slot overlay filter. `$type` is a FeedEventType (or "goal" for the players topic).
     *
     * @return array<string, mixed>
     */
    private function samplePayload(string $type, string $slot): array
    {
        $now = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);
        $slotInt = ctype_digit($slot) ? (int) $slot : 0;

        if ('goal' === $type) {
            // players-topic shape, with a marker the goals widget recognizes to celebrate immediately
            // (bypassing its load-time baseline suppression). The slot key drives the filter match.
            return [
                '__test__' => true,
                'slots' => [
                    ('' !== $slot ? $slot : '__test__') => [
                        'slot_name' => $slotInt > 0 ? sprintf('Slot %d', $slotInt) : 'Test Overlay',
                        'checks_done' => 100,
                        'checks_total' => 100,
                        'items_received' => 80,
                        'client_status' => 30,
                        'goal_reached_at' => $now,
                    ],
                ],
            ];
        }

        // Feed-event shape. The targeted player is the receiver (item/hint) or sender (location/chat), so
        // the per-slot filter (sender|receiver) keeps the event for that player.
        $player = [
            'slot' => $slotInt > 0 ? $slotInt : 1,
            'name' => $slotInt > 0 ? sprintf('Slot %d', $slotInt) : 'Joueur test',
            'game' => 'Wind Waker',
        ];
        $finder = ['slot' => 99, 'name' => 'Michel_M', 'game' => 'Mario 64'];
        $event = ['__test__' => true, 'type' => $type, 'timestamp' => $now, 'color' => 'plum'];

        return match ($type) {
            'location-checked' => $event + [
                'color' => 'blue',
                'text' => '🧪 Test - Bowser validé',
                'location' => ['id' => 300, 'name' => 'Bowser'],
                'sender' => $player,
            ],
            'hint' => $event + [
                'color' => 'salmon',
                'text' => '🧪 Test - Indice : Master Sword',
                'item' => ['id' => 500, 'name' => 'Master Sword'],
                'location' => ['id' => 300, 'name' => 'Bowser'],
                'sender' => $finder,
                'receiver' => $player,
            ],
            'chat' => $event + [
                'color' => 'white',
                'text' => '🧪 Test - gg !',
                'sender' => $player,
            ],
            default => $event + [
                'type' => 'item-received',
                'text' => '🧪 Test - Progressive Sword',
                'item' => ['id' => 500, 'name' => 'Progressive Sword'],
                'location' => ['id' => 300, 'name' => 'Bowser'],
                'sender' => $finder,
                'receiver' => $player,
            ],
        };
    }
}
