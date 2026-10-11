<?php

declare(strict_types=1);

namespace App\PersonalRuns\Infrastructure\Http;

use App\PersonalRuns\Application\Exception\RunListingDeliveryException;
use App\PersonalRuns\Application\Port\RunListingChannelInterface;
use App\PersonalRuns\Application\Support\RunListingAnnouncement;
use App\Shared\Infrastructure\Http\DiscordBotRest;
use App\Shared\Infrastructure\Http\DiscordRestFailure;

/**
 * The run listings in a Discord channel, told by the project's bot (story 43.27), on the model of the quests (41.26).
 * Titles and messages are written by members: only the configured role may ever be mentioned, so an `@everyone` in a
 * pitch stays text.
 */
final readonly class DiscordBotRunListingChannel implements RunListingChannelInterface
{
    public const int COLOR = 0x3BA55D;

    public function __construct(
        private DiscordBotRest $rest,
        private string $channelId,
        private string $roleId,
    ) {
    }

    public function isEnabled(): bool
    {
        return true;
    }

    public function post(RunListingAnnouncement $announcement, bool $mention): array
    {
        $mentioned = $mention && '' !== $this->roleId;
        $body = self::payload($announcement);
        if ($mentioned) {
            $body['content'] = sprintf('<@&%s> Une partie cherche des joueurs !', $this->roleId);
            $body['allowed_mentions'] = ['parse' => [], 'roles' => [$this->roleId]];
        }

        try {
            $sent = $this->rest->request('POST', $this->messages(), $body);
        } catch (DiscordRestFailure $e) {
            throw self::failure($e);
        }

        $id = $sent['id'] ?? null;
        if (!is_string($id) || '' === $id) {
            throw new RunListingDeliveryException('Discord answered without a message id.');
        }

        return ['messageId' => $id, 'mentioned' => $mentioned];
    }

    public function edit(string $messageId, RunListingAnnouncement $announcement): bool
    {
        try {
            // The content (and its mention) is left as posted: only the card changes, and an edit never pings.
            $this->rest->request('PATCH', $this->messages().'/'.$messageId, self::payload($announcement));
        } catch (DiscordRestFailure $e) {
            if (404 === $e->status) {
                return false;
            }

            throw self::failure($e);
        }

        return true;
    }

    public function delete(string $messageId): void
    {
        try {
            $this->rest->request('DELETE', $this->messages().'/'.$messageId);
        } catch (DiscordRestFailure $e) {
            if (404 !== $e->status) {
                throw self::failure($e);
            }
        }
    }

    /** @return array<string, mixed> */
    public static function payload(RunListingAnnouncement $announcement): array
    {
        $fields = [];
        if (null !== $announcement->plannedFor) {
            $fields[] = ['name' => 'Prévue', 'value' => sprintf('<t:%d:F>', $announcement->plannedFor->getTimestamp()), 'inline' => true];
        }
        $fields[] = ['name' => 'Places', 'value' => $announcement->seatsLabel(), 'inline' => true];
        $games = $announcement->gamesLabel();
        if (null !== $games) {
            $fields[] = ['name' => 'Jeux', 'value' => mb_substr($games, 0, 1024), 'inline' => false];
        }

        return [
            'allowed_mentions' => ['parse' => []],
            'embeds' => [[
                'title' => mb_substr($announcement->title, 0, 256),
                'description' => mb_substr($announcement->pitch, 0, 4096),
                'url' => $announcement->url,
                'color' => self::COLOR,
                'author' => ['name' => mb_substr('Par '.$announcement->hostName, 0, 256)],
                'fields' => $fields,
                'footer' => ['text' => 'Rejoins-la sur archilan.fr'],
            ]],
        ];
    }

    private function messages(): string
    {
        return '/channels/'.$this->channelId.'/messages';
    }

    private static function failure(DiscordRestFailure $e): RunListingDeliveryException
    {
        return new RunListingDeliveryException(0 !== $e->status ? sprintf('Discord answered HTTP %d.', $e->status) : 'Discord unreachable.', 0, $e);
    }
}
