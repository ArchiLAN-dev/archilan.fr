<?php

declare(strict_types=1);

namespace App\Registrations\Presentation\Controller;

use App\Registrations\Application\Service\EventSlotCoPlayers;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 11.10: the co-players of a slot of an event, managed by an admin or by the slot's holder.
 */
final readonly class EventSlotCoPlayersController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private EventSlotCoPlayers $coPlayers,
    ) {
    }

    #[Route('/api/v1/events/{eventId}/slots/{slotId}/co-players', name: 'api_events_slot_co_players_view', methods: ['GET'])]
    public function view(Request $request, string $eventId, string $slotId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->coPlayers->view($eventId, $slotId, $user->getId(), in_array('ROLE_ADMIN', $user->getRoles(), true));
        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Slot introuvable.', 404);
        }
        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        return new JsonResponse(['data' => [
            'coPlayers' => $result['coPlayers'],
            'candidates' => $result['candidates'],
            'canManage' => $result['canManage'],
        ]]);
    }

    /**
     * Replace the whole roster: the same call adds and removes, and repeating it changes nothing.
     */
    #[Route('/api/v1/events/{eventId}/slots/{slotId}/co-players', name: 'api_events_slot_co_players_replace', methods: ['PUT'])]
    public function replace(Request $request, string $eventId, string $slotId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $payload = json_decode($request->getContent(), true);
        $raw = is_array($payload) && is_array($payload['userIds'] ?? null) ? $payload['userIds'] : [];
        $userIds = array_values(array_filter($raw, is_string(...)));

        $result = $this->coPlayers->replace($eventId, $slotId, $user->getId(), in_array('ROLE_ADMIN', $user->getRoles(), true), $userIds);
        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Slot introuvable.', 404);
        }
        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Seuls un admin et le titulaire du slot gèrent ses co-joueurs.', 403);
        }
        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Co-joueurs invalides.', 422, $result['errors']);
        }

        return new JsonResponse(['data' => ['coPlayers' => $result['coPlayers']]]);
    }
}
