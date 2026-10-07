<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Wallet;

use App\Wallet\Application\Exception\QuestAnnouncementDeliveryException;
use App\Wallet\Application\Port\QuestAnnouncementChannelInterface;
use App\Wallet\Application\Support\QuestAnnouncement;

/**
 * Story 41.26: a Discord channel for the tests - records each announcement and the message it was given, answers
 * the same message (or `m1` for a new one), and can be switched off or made to fail.
 */
final class SpyQuestAnnouncementChannel implements QuestAnnouncementChannelInterface
{
    public bool $enabled = true;
    public bool $failing = false;
    /** @var list<QuestAnnouncement> */
    public array $posted = [];
    /** @var list<string|null> */
    public array $messageIds = [];

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function publish(QuestAnnouncement $announcement, ?string $messageId): string
    {
        if ($this->failing) {
            throw new QuestAnnouncementDeliveryException('Discord answered HTTP 403.');
        }
        $this->posted[] = $announcement;
        $this->messageIds[] = $messageId;

        return $messageId ?? 'm1';
    }
}
