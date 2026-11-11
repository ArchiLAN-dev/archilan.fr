<?php

declare(strict_types=1);

namespace App\Content\Domain\Repository;

use App\Content\Domain\Entity\HelpGuide;

interface HelpGuideRepositoryInterface
{
    public function find(string $id): ?HelpGuide;

    public function findBySlug(string $slug): ?HelpGuide;

    /** @return list<HelpGuide> in display order: position, then oldest first */
    public function all(): array;

    public function save(HelpGuide $guide): void;

    /** @param list<HelpGuide> $guides saved in one flush */
    public function saveAll(array $guides): void;

    public function remove(HelpGuide $guide): void;
}
