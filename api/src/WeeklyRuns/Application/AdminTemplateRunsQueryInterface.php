<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

interface AdminTemplateRunsQueryInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function execute(string $templateId): array;
}
