<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Sessions\Application\Query\SessionResultsQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class SessionResultsController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionResultsQuery $sessionResultsQuery,
    ) {
    }

    #[Route('/api/v1/events/{eventId}/session/results', methods: ['GET'])]
    public function results(string $eventId, Request $request): JsonResponse
    {
        // Auth is optional: a public event's results are open, a private one's follow its recap audience.
        $result = $this->sessionResultsQuery->findForEvent($eventId, $this->apiAccessGuard->optionalUser($request)?->getId());
        if (null === $result) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Événement introuvable.', 404);
        }

        if (null === $result['session']) {
            return new JsonResponse(['data' => null]);
        }

        return new JsonResponse(['data' => ['session' => $result['session'], 'slots' => $result['slots']]]);
    }
}
