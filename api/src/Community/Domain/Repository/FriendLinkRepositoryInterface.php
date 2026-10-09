<?php

declare(strict_types=1);

namespace App\Community\Domain\Repository;

use App\Community\Domain\Entity\FriendLink;

interface FriendLinkRepositoryInterface
{
    public function findByUserId(string $userId): ?FriendLink;

    public function findByCode(string $code): ?FriendLink;

    public function save(FriendLink $link): void;
}
