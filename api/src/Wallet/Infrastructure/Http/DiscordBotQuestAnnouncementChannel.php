<?php

declare(strict_types=1);

namespace App\Wallet\Infrastructure\Http;

use App\Shared\Infrastructure\Http\DiscordBotRest;
use App\Shared\Infrastructure\Http\DiscordRestFailure;
use App\Wallet\Application\Exception\QuestAnnouncementDeliveryException;
use App\Wallet\Application\Port\QuestAnnouncementChannelInterface;
use App\Wallet\Application\Support\QuestAnnouncement;

/**
 * The quests of the week in a Discord channel, told by the project's bot (story 41.26, after the webhook of 41.24).
 * The week's message is edited rather than posted again; a message deleted on Discord is posted anew. No mention is
 * ever resolved: quest titles are written by admins, and an `@everyone` in one must stay text.
 */
final readonly class DiscordBotQuestAnnouncementChannel implements QuestAnnouncementChannelInterface
{
    public const int COLOR = 0xF5A623;

    public function __construct(
        private DiscordBotRest $rest,
        private string $channelId,
    ) {
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function publish(QuestAnnouncement $announcement, ?string $messageId): string
    {
        $body = self::payload($announcement);
        $path = '/channels/'.$this->channelId.'/messages';

        try {
            if (null !== $messageId) {
                try {
                    $this->rest->request('PATCH', $path.'/'.$messageId, $body);

                    return $messageId;
                } catch (DiscordRestFailure $e) {
                    // Deleted on Discord (or never there): the announcement gets a message of its own again.
                    if (404 !== $e->status) {
                        throw $e;
                    }
                }
            }
            $sent = $this->rest->request('POST', $path, $body);
        } catch (DiscordRestFailure $e) {
            throw new QuestAnnouncementDeliveryException(0 !== $e->status ? sprintf('Discord answered HTTP %d.', $e->status) : 'Discord unreachable.', 0, $e);
        }

        $id = $sent['id'] ?? null;
        if (!is_string($id) || '' === $id) {
            throw new QuestAnnouncementDeliveryException('Discord answered without a message id.');
        }

        return $id;
    }

    /** @return array<string, mixed> */
    public static function payload(QuestAnnouncement $announcement): array
    {
        $fields = array_map(static fn (array $quest): array => [
            'name' => mb_substr(sprintf('%s · +%d pelles', $quest['title'], $quest['reward']), 0, 256),
            'value' => mb_substr($quest['objectives'], 0, 1024),
            'inline' => false,
        ], $announcement->quests);
        if ($announcement->chestReward > 0) {
            $fields[] = ['name' => sprintf('Coffre de la semaine · +%d pelles', $announcement->chestReward), 'value' => 'Pour qui fait toutes les quêtes.', 'inline' => false];
        }

        return [
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
