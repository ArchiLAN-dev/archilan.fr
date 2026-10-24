<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Repository;

use App\GameSelection\Domain\Entity\GameFollow;

interface GameFollowRepositoryInterface
{
    public function find(string $userId, string $gameId): ?GameFollow;

    /**
     * The members who follow a game (story 38.22): the ones told of its new apworlds.
     *
     * @return list<string>
     */
    public function followerIds(string $gameId): array;

    /**
     * The games a member follows (story 38.23).
     *
     * @return list<string>
     */
    public function followedGameIds(string $userId): array;

    public function save(GameFollow $follow): void;

    public function remove(GameFollow $follow): void;

    /** A removed game takes its follows with it. */
    public function deleteByGameId(string $gameId): void;

    /** An erased account takes its follows with it. */
    public function deleteByUserId(string $userId): void;
}
