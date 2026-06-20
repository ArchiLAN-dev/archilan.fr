<?php

declare(strict_types=1);

namespace App\GameSelection\Domain;

interface GameTutorialContributionRepositoryInterface
{
    public function save(GameTutorialContribution $contribution): void;

    public function findById(string $id): ?GameTutorialContribution;

    public function countPendingForGame(string $authorId, string $gameId): int;

    public function countPendingForProposedName(string $authorId, string $proposedGameName): int;
}
