<?php

declare(strict_types=1);

namespace App\GameSelection\Infrastructure\Adapter;

use App\GameSelection\Application\Port\StaffAlertChannelInterface;
use App\GameSelection\Infrastructure\Http\DiscordWebhookStaffAlertChannel;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Picks the staff channel from configuration (story 38.2), so no handler ever branches on whether
 * Discord is set up. Wired as the service factory of {@see StaffAlertChannelInterface}.
 */
final class StaffAlertChannelFactory
{
    public static function create(HttpClientInterface $httpClient, string $discordStaffWebhookUrl): StaffAlertChannelInterface
    {
        $url = trim($discordStaffWebhookUrl);

        return '' === $url
            ? new DisabledStaffAlertChannel()
            : new DiscordWebhookStaffAlertChannel($httpClient, $url);
    }
}
