<?php

declare(strict_types=1);

namespace App\Community\Application\Message;

/**
 * Scheduled every night (story 39.6): time out again each member still suspended on the site, Discord capping
 * a timeout at 28 days.
 */
final readonly class ExtendDiscordTimeoutsMessage
{
}
