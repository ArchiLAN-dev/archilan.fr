<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Adapter;

use App\Wallet\Application\Port\QuestAnnouncementChannelInterface;
use App\Wallet\Application\Support\QuestAnnouncement;

/** No Discord webhook configured (story 41.24): the quests are only announced on the site. */
final readonly class DisabledQuestAnnouncementChannel implements QuestAnnouncementChannelInterface
{
    public function post(QuestAnnouncement $announcement): void
    {
    }
}
