<?php

declare(strict_types=1);

namespace App\Events\Application;

use Psr\Log\LoggerInterface;

final readonly class AdminDashboardStats
{
    public function __construct(
        private DashboardStatsQueryInterface $statsQuery,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{publishedEvents: int, totalActiveRegistrations: int, gameCount: int, userCount: int, activeMemberCount: int, totalRevenueCents: int}
     */
    public function getStats(): array
    {
        $stats = $this->statsQuery->getStats();

        $this->logger->debug('AdminDashboardStats.getStats', $stats);

        return $stats;
    }
}
