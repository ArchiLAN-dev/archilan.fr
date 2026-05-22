<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

use App\GameSelection\Domain\GameRequest;
use App\GameSelection\Domain\GameRequestRepositoryInterface;

final readonly class GameRequests
{
    public function __construct(
        private GameRequestRepositoryInterface $repository,
        private GameRequestListQueryInterface $listQuery,
    ) {
    }

    /**
     * @return list<array{normalizedName: string, displayName: string, voteCount: int, hasVoted: bool}>
     */
    public function list(?string $userId): array
    {
        return $this->listQuery->list($userId);
    }

    public function submit(string $gameName, string $userId, \DateTimeImmutable $now): void
    {
        $request = GameRequest::create($gameName, $userId, $now);
        $this->repository->save($request);
    }

    public function cancel(string $normalizedName, string $userId): void
    {
        $request = $this->repository->findByNormalizedNameAndUserId($normalizedName, $userId);

        if (null === $request) {
            return;
        }

        $this->repository->remove($request);
    }
}
