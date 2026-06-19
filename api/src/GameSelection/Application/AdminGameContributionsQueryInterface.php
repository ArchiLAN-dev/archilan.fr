<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

interface AdminGameContributionsQueryInterface
{
    /**
     * @return list<array{
     *   id: string,
     *   status: string,
     *   createdAt: string,
     *   authorName: string,
     *   message: string|null,
     *   target: string,
     *   gameSlug: string|null,
     *   proposedSteps: list<array{type: string, title: string, description: string, links: list<array{label: string, url: string|null}>}>,
     *   currentSteps: list<array{type: string, title: string, description: string, links: list<array{label: string, url: string|null}>}>
     * }>
     */
    public function list(ContributionQueryFilters $filters): array;

    public function pendingCount(): int;
}
