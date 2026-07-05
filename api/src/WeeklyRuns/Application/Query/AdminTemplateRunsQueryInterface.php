<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application\Query;

interface AdminTemplateRunsQueryInterface
{
    /**
     * @return list<array<string, mixed>>
     */
    public function execute(string $templateId): array;
}
