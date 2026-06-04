<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

interface AdminWeeklyTemplateListQueryInterface
{
    /**
     * @return array{data: list<array<string, mixed>>, meta: array{total: int}}
     */
    public function execute(): array;
}
