<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Domain\Entity\DiscordBanNotice;
use App\Community\Domain\Repository\DiscordBanNoticeRepositoryInterface;

/**
 * In-memory notices of the Discord bans the site does not apply (story 39.7 tests).
 */
final class InMemoryDiscordBanNoticeRepository implements DiscordBanNoticeRepositoryInterface
{
    /** @var array<string, DiscordBanNotice> */
    public array $notices = [];

    public function all(): array
    {
        return array_values($this->notices);
    }

    public function save(DiscordBanNotice $notice): void
    {
        $this->notices[$notice->getDiscordUserId()] = $notice;
    }

    public function remove(DiscordBanNotice $notice): void
    {
        unset($this->notices[$notice->getDiscordUserId()]);
    }

    public function flush(): void
    {
    }
}
