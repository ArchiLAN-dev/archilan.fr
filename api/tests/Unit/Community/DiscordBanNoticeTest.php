<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Domain\Entity\DiscordBanNotice;
use PHPUnit\Framework\TestCase;

/**
 * Story 39.7: a Discord ban the site does not apply is told to the staff once.
 */
final class DiscordBanNoticeTest extends TestCase
{
    public function testItRemembersWhoAndWhy(): void
    {
        $notice = DiscordBanNotice::record('11', DiscordBanNotice::REASON_UNLINKED, new \DateTimeImmutable('2026-09-27 12:00:00'));

        self::assertSame('11', $notice->getDiscordUserId());
        self::assertSame(DiscordBanNotice::REASON_UNLINKED, $notice->getReason());
    }
}
