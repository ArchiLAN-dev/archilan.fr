<?php

declare(strict_types=1);

namespace App\Wallet\Application\Message;

/** Story 41.24: tell the week's quests on Discord, from the worker. */
final readonly class AnnounceQuestsOnDiscordJob
{
    public function __construct(public string $weekKey)
    {
    }
}
