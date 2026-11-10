<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Sessions\Application\Query\DependencyMapQuery;
use App\Sessions\Domain\Service\DependencyRisk;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Story 52.7: the staff's dependency map of an event, before the launch. `?spheres=N` sets the early spheres. */
final readonly class DependencyMapController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private DependencyMapQuery $map,
    ) {
    }

    #[Route('/api/v1/admin/events/{eventId}/dependency-map', name: 'api_admin_event_dependency_map', methods: ['GET'])]
    public function __invoke(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $spheres = $request->query->getInt('spheres', DependencyRisk::DEFAULT_SPHERE_LIMIT);

        return new JsonResponse(['data' => $this->map->forEvent($eventId, $spheres)->toArray()]);
    }
}
