<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Identity\Domain\Entity\User;
use App\Sessions\Application\Command\HandleDeparture;
use App\Sessions\Application\Command\RequestDeparture;
use App\Sessions\Application\Query\DepartureQuery;
use App\Sessions\Application\Query\DepartureRow;
use App\Sessions\Application\Query\SessionQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 52.6: « Je pars » for the player of a slot; the departures to handle for the staff of an event or the owner
 * of a private run, who release the world or dismiss the request. A player never releases.
 */
final readonly class DepartureController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionQuery $sessionQuery,
        private DepartureQuery $departures,
        private RequestDeparture $request,
        private HandleDeparture $handle,
    ) {
    }

    #[Route('/api/v1/sessions/{sessionId}/slots/{slotIndex}/departure', name: 'api_sessions_departure_get', requirements: ['slotIndex' => '\d+'], methods: ['GET'])]
    public function mine(Request $request, string $sessionId, int $slotIndex): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        if (!$this->ownsSlot($user, $sessionId, $slotIndex)) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        $state = $this->departures->forPlayer($sessionId, $slotIndex);

        return new JsonResponse(['data' => [
            'available' => $state['available'],
            'requestedAt' => $state['requestedAt']?->format(\DateTimeInterface::ATOM),
        ]]);
    }

    #[Route('/api/v1/sessions/{sessionId}/slots/{slotIndex}/departure', name: 'api_sessions_departure_request', requirements: ['slotIndex' => '\d+'], methods: ['POST'])]
    public function leave(Request $request, string $sessionId, int $slotIndex): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        if (!$this->ownsSlot($user, $sessionId, $slotIndex)) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        $this->request->execute($sessionId, $slotIndex);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/v1/sessions/{sessionId}/departures', name: 'api_sessions_departures', methods: ['GET'])]
    public function toHandle(Request $request, string $sessionId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => array_map(static fn (DepartureRow $row): array => [
            'id' => $row->id,
            'slotIndex' => $row->slotIndex,
            'slotName' => $row->slotName,
            'origin' => $row->origin,
            'createdAt' => $row->createdAt->format(\DateTimeInterface::ATOM),
        ], $this->departures->toHandle($sessionId, $user->getId(), self::isAdmin($user)))]);
    }

    #[Route('/api/v1/sessions/{sessionId}/departures/{requestId}/release', name: 'api_sessions_departure_release', methods: ['POST'])]
    public function release(Request $request, string $sessionId, string $requestId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $this->handle->release($user->getId(), self::isAdmin($user), $sessionId, $requestId);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    #[Route('/api/v1/sessions/{sessionId}/departures/{requestId}/dismiss', name: 'api_sessions_departure_dismiss', methods: ['POST'])]
    public function dismiss(Request $request, string $sessionId, string $requestId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $this->handle->dismiss($user->getId(), self::isAdmin($user), $sessionId, $requestId);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function ownsSlot(User $user, string $sessionId, int $slotIndex): bool
    {
        return self::isAdmin($user) || $this->sessionQuery->doesUserOwnSlot($user->getId(), $sessionId, $slotIndex);
    }

    private static function isAdmin(User $user): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles(), true);
    }
}
