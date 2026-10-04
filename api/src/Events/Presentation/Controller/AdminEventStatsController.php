<?php

declare(strict_types=1);

namespace App\Events\Presentation\Controller;

use App\Events\Application\Query\EventStatsQueryInterface;
use App\Shared\Application\Support\StatsPeriod;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Events section of the admin statistics page (story 42.3).
 */
final readonly class AdminEventStatsController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private EventStatsQueryInterface $stats,
        private ClockInterface $clock,
    ) {
    }

    #[Route('/api/v1/admin/statistiques/evenements', name: 'api_events_admin_stats', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $now = $this->clock->now();
        $period = StatsPeriod::fromCode($request->query->getString('period') ?: null, $now);

        return new JsonResponse(['period' => $period->describe()] + $this->stats->stats($period, $now));
    }
}
