<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Domain;

interface WeeklyTemplateRepositoryInterface
{
    public function findById(string $id): ?WeeklyTemplate;

    /**
     * @return list<WeeklyTemplate>
     */
    public function findAllActive(): array;

    public function save(WeeklyTemplate $template): void;

    public function flush(): void;
}
