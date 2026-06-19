<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

interface MyGameTutorialContributionsQueryInterface
{
    /**
     * @return list<array{id: string, status: string, target: string, stepCount: int, createdAt: string}>
     */
    public function forAuthor(string $authorId): array;
}
