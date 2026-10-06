<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Http;

use App\Wallet\Application\Port\QuestAnnouncementChannelInterface;
use App\Wallet\Application\Support\QuestAnnouncement;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Posts the quests of the week to a Discord incoming webhook (story 41.24): a plain JSON POST on a secret URL, no
 * bot involved. No mention is ever resolved (quest titles are written by admins, but an `@everyone` in one must stay
 * text), and the URL - the credential - never reaches an exception message.
 */
final readonly class DiscordWebhookQuestAnnouncementChannel implements QuestAnnouncementChannelInterface
{
    public const int COLOR = 0xF5A623;

    private const string USERNAME = 'ArchiLAN - quêtes de la semaine';
    private const float TIMEOUT_SECONDS = 5.0;

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $webhookUrl,
    ) {
    }

    public function post(QuestAnnouncement $announcement): void
    {
        try {
            $status = $this->httpClient->request('POST', $this->webhookUrl, [
                'json' => self::payload($announcement),
                'timeout' => self::TIMEOUT_SECONDS,
            ])->getStatusCode();
        } catch (ExceptionInterface $e) {
            throw new \RuntimeException(sprintf('Discord webhook unreachable (%s).', $e::class));
        }

        if ($status < 200 || $status >= 300) {
            throw new \RuntimeException(sprintf('Discord webhook answered HTTP %d.', $status));
        }
    }

    /** @return array<string, mixed> */
    public static function payload(QuestAnnouncement $announcement): array
    {
        $fields = array_map(static fn (array $quest): array => [
            'name' => sprintf('%s · +%d pelles', $quest['title'], $quest['reward']),
            'value' => $quest['objectives'],
            'inline' => false,
        ], $announcement->quests);
        if ($announcement->chestReward > 0) {
            $fields[] = ['name' => sprintf('Coffre de la semaine · +%d pelles', $announcement->chestReward), 'value' => 'Pour qui fait toutes les quêtes.', 'inline' => false];
        }

        return [
            'username' => self::USERNAME,
            'allowed_mentions' => ['parse' => []],
            'embeds' => [[
                'title' => 'Les quêtes de la semaine sont là',
                'description' => sprintf('Jusqu\'à %d pelles à gagner. Nouvelles quêtes le <t:%d:F>.', $announcement->maxPelles(), $announcement->renewsAt->getTimestamp()),
                'url' => $announcement->url,
                'color' => self::COLOR,
                'fields' => $fields,
            ]],
        ];
    }
}
