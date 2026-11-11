<?php

declare(strict_types=1);

namespace App\Events\Presentation\Controller;

use App\Events\Application\Query\EventPracticalInfoQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Story 53.4: the registrants-only practical information of an event. */
final readonly class EventPracticalInfoController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private EventPracticalInfoQuery $practicalInfoQuery,
    ) {
    }

    #[Route('/api/v1/events/{eventId}/practical-info', name: 'api_events_practical_info', methods: ['GET'])]
    public function __invoke(Request $request, string $eventId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $isAdmin = in_array('ROLE_ADMIN', $user->getRoles(), true);

        return new JsonResponse([
            'data' => $this->practicalInfoQuery->forViewer($eventId, $user->getId(), $isAdmin),
            'meta' => [],
        ]);
    }
}
