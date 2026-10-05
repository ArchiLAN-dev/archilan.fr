<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Query;

/**
 * @phpstan-type ContributionStep array{type: string, title: string, description: string, videoUrl: string|null}
 * @phpstan-type Contribution array{
 *   id: string,
 *   status: string,
 *   createdAt: string,
 *   authorName: string,
 *   message: string|null,
 *   target: string,
 *   gameSlug: string|null,
 *   gameId: string|null,
 *   reviewedAt: string|null,
 *   rejectionReason: string|null,
 *   proposedSteps: list<ContributionStep>,
 *   currentSteps: list<ContributionStep>
 * }
 */
interface AdminGameContributionsQueryInterface
{
    /**
     * @return list<Contribution>
     */
    public function list(ContributionQueryFilters $filters): array;

    /**
     * One contribution, for its own moderation page (story 39.16); null when unknown.
     *
     * @return Contribution|null
     */
    public function find(string $id): ?array;

    public function pendingCount(): int;
}
