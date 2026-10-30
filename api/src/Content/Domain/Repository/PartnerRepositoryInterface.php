<?php

declare(strict_types=1);

namespace App\Content\Domain\Repository;

use App\Content\Domain\Entity\Partner;

interface PartnerRepositoryInterface
{
    public function find(string $id): ?Partner;

    /** @return list<Partner> in display order: position, then oldest first */
    public function all(): array;

    public function save(Partner $partner): void;

    /** @param list<Partner> $partners saved in one flush */
    public function saveAll(array $partners): void;

    public function remove(Partner $partner): void;
}
