<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Application;

final readonly class AdminWeeklyTemplateListQuery
{
    public function __construct(private AdminWeeklyTemplateListQueryInterface $query)
    {
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{total: int}}
     */
    public function execute(): array
    {
        return $this->query->execute();
    }
}
