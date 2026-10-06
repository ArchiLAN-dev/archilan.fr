<?php

declare(strict_types=1);

namespace App\Wallet\Application\Port;

use App\Wallet\Application\Support\QuestAnnouncement;

/** Where the quests of the week are told outside the site (story 41.24): a Discord channel, or nowhere. */
interface QuestAnnouncementChannelInterface
{
    /** @throws \RuntimeException when the message could not be delivered */
    public function post(QuestAnnouncement $announcement): void;
}
