<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Domain;

interface WeeklyRunRepositoryInterface
{
    public function findById(string $id): ?WeeklyRun;

    /**
     * @return list<WeeklyRun>
     */
    public function findAllActive(): array;

    public function existsByTemplateAndWeek(string $templateId, int $weekYear, int $weekNumber): bool;

    public function save(WeeklyRun $run): void;

    public function flush(): void;
}
