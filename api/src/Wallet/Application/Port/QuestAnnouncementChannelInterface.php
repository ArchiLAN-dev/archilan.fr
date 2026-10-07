<?php

declare(strict_types=1);

namespace App\Wallet\Application\Port;

use App\Wallet\Application\Exception\QuestAnnouncementDeliveryException;
use App\Wallet\Application\Support\QuestAnnouncement;

/**
 * Where the quests of the week are told outside the site (story 41.24): a Discord channel, or nowhere. Story 41.26:
 * the bot speaks there, one message a week that a new announcement updates.
 */
interface QuestAnnouncementChannelInterface
{
    /** Whether the announcements go anywhere at all. */
    public function isEnabled(): bool;

    /**
     * Posts the announcement, or updates the message already posted for its week (posting a new one when that
     * message is gone).
     *
     * @return string the id of the message now holding the announcement
     *
     * @throws QuestAnnouncementDeliveryException
     */
    public function publish(QuestAnnouncement $announcement, ?string $messageId): string;
}
