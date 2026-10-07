<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Adapter;

use App\Shared\Infrastructure\Http\DiscordBotRest;
use App\Wallet\Application\Port\QuestAnnouncementChannelInterface;
use App\Wallet\Infrastructure\Http\DiscordBotQuestAnnouncementChannel;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Picks the quest announcement channel from configuration (stories 41.24, 41.26), so nothing branches on whether
 * Discord is set up. Wired as the service factory of {@see QuestAnnouncementChannelInterface}.
 */
final class QuestAnnouncementChannelFactory
{
    public static function create(HttpClientInterface $httpClient, string $botToken, string $channelId): QuestAnnouncementChannelInterface
    {
        $token = trim($botToken);
        $channel = trim($channelId);

        return '' === $token || '' === $channel
            ? new DisabledQuestAnnouncementChannel()
            : new DiscordBotQuestAnnouncementChannel(new DiscordBotRest($httpClient, $token), $channel);
    }
}
