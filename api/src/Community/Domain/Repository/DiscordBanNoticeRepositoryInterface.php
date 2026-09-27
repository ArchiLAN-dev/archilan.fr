<?php

declare(strict_types=1);

namespace App\Community\Domain\Repository;

use App\Community\Domain\Entity\DiscordBanNotice;

interface DiscordBanNoticeRepositoryInterface
{
    /**
     * @return list<DiscordBanNotice>
     */
    public function all(): array;

    /** Tracks a new notice; written by the next flush. */
    public function save(DiscordBanNotice $notice): void;

    public function remove(DiscordBanNotice $notice): void;

    public function flush(): void;
}
