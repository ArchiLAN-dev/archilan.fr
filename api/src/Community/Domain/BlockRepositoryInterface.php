<?php

declare(strict_types=1);

namespace App\Community\Domain;

interface BlockRepositoryInterface
{
    public function find(string $blockerId, string $blockedId): ?Block;

    /** Whether a block exists in either direction between the two users. */
    public function existsEitherWay(string $a, string $b): bool;

    public function save(Block $block): void;

    public function remove(Block $block): void;
}
