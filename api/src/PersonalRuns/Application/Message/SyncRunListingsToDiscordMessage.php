<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Message;

/**
 * Story 43.27: bring the Discord channel in line with the listings page. Scheduled every five minutes, and dispatched
 * once a listing is published or joined so the channel hears of it at once.
 */
final readonly class SyncRunListingsToDiscordMessage
{
}
