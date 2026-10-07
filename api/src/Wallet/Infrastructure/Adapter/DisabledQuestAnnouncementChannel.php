<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Adapter;

use App\Wallet\Application\Exception\QuestAnnouncementDeliveryException;
use App\Wallet\Application\Port\QuestAnnouncementChannelInterface;
use App\Wallet\Application\Support\QuestAnnouncement;

/** No Discord channel or no bot configured (stories 41.24, 41.26): the quests are only announced on the site. */
final readonly class DisabledQuestAnnouncementChannel implements QuestAnnouncementChannelInterface
{
    public function isEnabled(): bool
    {
        return false;
    }

    public function publish(QuestAnnouncement $announcement, ?string $messageId): string
    {
        throw new QuestAnnouncementDeliveryException('No Discord channel configured for the quests.');
    }
}
