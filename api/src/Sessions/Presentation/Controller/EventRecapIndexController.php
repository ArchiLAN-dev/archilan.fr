<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Sessions\Application\Query\EventRecapIndexQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Public list of a public event's finished sessions that have a recap - each entry links to
 * the /parties/{sessionId} page (story 32.3). No auth: same exposure model as the recap itself.
 */
final readonly class EventRecapIndexController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private EventRecapIndexQuery $eventRecapIndexQuery,
    ) {
    }

    #[Route('/api/v1/events/{eventId}/parties', methods: ['GET'])]
    public function index(string $eventId): JsonResponse
    {
        $entries = $this->eventRecapIndexQuery->execute($eventId);
        if (null === $entries) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Événement introuvable.', 404);
        }

        return new JsonResponse(['data' => $entries]);
    }
}
