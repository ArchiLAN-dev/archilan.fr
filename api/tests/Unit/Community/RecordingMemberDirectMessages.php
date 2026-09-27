<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Exception\MemberDirectMessageException;
use App\Community\Application\Port\IncomingDirectMessage;
use App\Community\Application\Port\MemberDirectMessageInterface;
use App\Community\Application\Port\SentDirectMessage;
use App\Community\Application\Support\ModerationForumMessage;

/**
 * Stands in for the bot's direct messages (stories 39.3 and 39.4 tests): records what it sends, serves an
 * inbox per channel, and can be told to fail.
 */
final class RecordingMemberDirectMessages implements MemberDirectMessageInterface
{
    /** @var list<array{discordUserId: string, message: ModerationForumMessage}> */
    public array $sent = [];

    /** @var array<string, list<IncomingDirectMessage>> channel id => its messages, oldest first */
    public array $inbox = [];

    public ?MemberDirectMessageException $failWith = null;

    public ?MemberDirectMessageException $failReadingWith = null;

    /** @var list<string> channels whose reading fails as Discord being down */
    public array $failingChannels = [];

    public function __construct(private readonly bool $configured = true)
    {
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(string $discordUserId, ModerationForumMessage $message): SentDirectMessage
    {
        if (null !== $this->failWith) {
            throw $this->failWith;
        }
        $this->sent[] = ['discordUserId' => $discordUserId, 'message' => $message];

        return new SentDirectMessage('dm-'.$discordUserId, (string) (1000 + \count($this->sent)));
    }

    public function messagesAfter(string $channelId, string $afterMessageId): array
    {
        if (null !== $this->failReadingWith) {
            throw $this->failReadingWith;
        }
        if (\in_array($channelId, $this->failingChannels, true)) {
            throw new MemberDirectMessageException('Discord 503', transient: true);
        }

        return array_values(array_filter(
            $this->inbox[$channelId] ?? [],
            static fn (IncomingDirectMessage $m): bool => (int) $m->id > (int) $afterMessageId,
        ));
    }
}
