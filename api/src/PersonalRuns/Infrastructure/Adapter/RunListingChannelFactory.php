<?php

declare(strict_types=1);

namespace App\PersonalRuns\Infrastructure\Adapter;

use App\PersonalRuns\Application\Port\RunListingChannelInterface;
use App\PersonalRuns\Infrastructure\Http\DiscordBotRunListingChannel;
use App\Shared\Infrastructure\Http\DiscordBotRest;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Picks the run listing channel from configuration (story 43.27), so nothing branches on whether Discord is set up.
 * Wired as the service factory of {@see RunListingChannelInterface}.
 */
final class RunListingChannelFactory
{
    public static function create(HttpClientInterface $httpClient, string $botToken, string $channelId, string $roleId): RunListingChannelInterface
    {
        $token = trim($botToken);
        $channel = trim($channelId);

        return '' === $token || '' === $channel
            ? new DisabledRunListingChannel()
            : new DiscordBotRunListingChannel(new DiscordBotRest($httpClient, $token), $channel, trim($roleId));
    }
}
