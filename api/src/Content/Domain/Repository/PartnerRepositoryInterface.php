<?php

declare(strict_types=1);

namespace App\Content\Domain\Repository;

use App\Content\Domain\Entity\Partner;
use App\Content\Domain\Entity\TrackedLink;

interface PartnerRepositoryInterface
{
    public function find(string $id): ?Partner;

    /** @return list<Partner> in display order: position, then oldest first */
    public function all(): array;

    /** Story 55.3: with the partner's link, in the same flush, when given. */
    public function save(Partner $partner, ?TrackedLink $link = null): void;

    /** @param list<Partner> $partners saved in one flush */
    public function saveAll(array $partners): void;

    /** Story 55.3: the partner's link, turned off, is saved in the same flush. */
    public function remove(Partner $partner, ?TrackedLink $link = null): void;
}
