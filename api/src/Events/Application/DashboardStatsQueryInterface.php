<?php

declare(strict_types=1);

namespace App\Events\Application;

interface DashboardStatsQueryInterface
{
    /**
     * @return array{publishedEvents: int, totalActiveRegistrations: int, gameCount: int, userCount: int, activeMemberCount: int, totalRevenueCents: int}
     */
    public function getStats(): array;
}
