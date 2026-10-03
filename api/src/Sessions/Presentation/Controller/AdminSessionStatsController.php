<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Sessions\Application\Query\SessionStatsQueryInterface;
use App\Shared\Application\Support\StatsPeriod;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The Parties section of the admin statistics page (story 42.2).
 */
final readonly class AdminSessionStatsController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionStatsQueryInterface $stats,
        private ClockInterface $clock,
    ) {
    }

    #[Route('/api/v1/admin/stats/sessions', name: 'api_sessions_admin_stats', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $period = StatsPeriod::fromCode($request->query->getString('period') ?: null, $this->clock->now());

        return new JsonResponse(['period' => $period->describe()] + $this->stats->stats($period));
    }
}
