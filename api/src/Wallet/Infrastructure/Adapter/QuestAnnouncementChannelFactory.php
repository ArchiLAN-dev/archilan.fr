<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Adapter;

use App\Wallet\Application\Port\QuestAnnouncementChannelInterface;
use App\Wallet\Infrastructure\Http\DiscordWebhookQuestAnnouncementChannel;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Picks the quest announcement channel from configuration (story 41.24), so the job never branches on whether
 * Discord is set up. Wired as the service factory of {@see QuestAnnouncementChannelInterface}.
 */
final class QuestAnnouncementChannelFactory
{
    public static function create(HttpClientInterface $httpClient, string $discordQuestsWebhookUrl): QuestAnnouncementChannelInterface
    {
        $url = trim($discordQuestsWebhookUrl);

        return '' === $url
            ? new DisabledQuestAnnouncementChannel()
            : new DiscordWebhookQuestAnnouncementChannel($httpClient, $url);
    }
}
