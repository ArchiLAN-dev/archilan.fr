<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Sessions\Application\Query\EventForecastQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 56.8: an event's live forecast and the comparison of expected and real durations, for admins.
 */
final readonly class EventForecastController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private EventForecastQuery $forecasts,
        private ClockInterface $clock,
    ) {
    }

    #[Route('/api/v1/admin/events/{eventId}/forecast', name: 'api_admin_events_forecast', methods: ['GET'])]
    public function __invoke(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $forecast = $this->forecasts->forEvent($eventId, $this->clock->now());
        if (null === $forecast) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Événement introuvable.', 404);
        }
        $response = new JsonResponse(['data' => $forecast]);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }
}
