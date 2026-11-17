<?php

declare(strict_types=1);

namespace App\Content\Domain\Repository;

use App\Content\Domain\Entity\TrackedLink;

interface TrackedLinkRepositoryInterface
{
    public function find(string $id): ?TrackedLink;

    public function findByCode(string $code): ?TrackedLink;

    /** Story 55.3: the link of that partner, if it has one. */
    public function findByPartner(string $partnerId): ?TrackedLink;

    /** @return list<TrackedLink> newest first */
    public function all(): array;

    public function save(TrackedLink $link): void;

    /**
     * One more visit of the link on that day from that host (story 55.1), in one statement: two visits at the same
     * time both count.
     *
     * @param string $day          `Y-m-d`, the day in Paris
     * @param string $referrerHost the host the visitor came from, empty when unknown
     */
    public function countVisit(string $linkId, string $day, string $referrerHost): void;
}
