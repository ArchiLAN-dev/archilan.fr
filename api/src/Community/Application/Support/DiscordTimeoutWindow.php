<?php

declare(strict_types=1);

namespace App\Community\Application\Support;

use App\Community\Application\Port\DiscordServerSanctionsInterface;

/**
 * How long a Discord timeout can mirror a suspension of the site (story 39.6): until the suspension ends, or
 * for Discord's 28 days less a minute, the daily extension taking over from there.
 */
final class DiscordTimeoutWindow
{
    public static function until(string $suspendedUntil, \DateTimeImmutable $now): \DateTimeImmutable
    {
        $utc = new \DateTimeZone('UTC');
        $end = new \DateTimeImmutable($suspendedUntil)->setTimezone($utc);
        $cap = $now->setTimezone($utc)->add(new \DateInterval(DiscordServerSanctionsInterface::TIMEOUT_CAP))->sub(new \DateInterval('PT1M'));

        return min($end, $cap);
    }
}
