<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\SessionResultsQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class SessionResultsController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionResultsQuery $sessionResultsQuery,
    ) {
    }

    #[Route('/api/v1/events/{eventId}/session/results', methods: ['GET'])]
    public function results(string $eventId): JsonResponse
    {
        $result = $this->sessionResultsQuery->findForEvent($eventId);
        if (null === $result) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Événement introuvable.', 404);
        }

        if (null === $result['session']) {
            return new JsonResponse(['data' => null]);
        }

        return new JsonResponse(['data' => ['session' => $result['session'], 'slots' => $result['slots']]]);
    }
}
