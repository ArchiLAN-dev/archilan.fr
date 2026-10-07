<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

/** Story 41.26: what an announcement did on Discord - a new message, or the week's message updated. */
enum QuestDiscordAnnouncement: string
{
    case Posted = 'posted';
    case Updated = 'updated';
}
