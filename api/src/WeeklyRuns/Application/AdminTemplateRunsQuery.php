<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

final readonly class AdminTemplateRunsQuery
{
    public function __construct(private AdminTemplateRunsQueryInterface $query)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function execute(string $templateId): array
    {
        return $this->query->execute($templateId);
    }
}
