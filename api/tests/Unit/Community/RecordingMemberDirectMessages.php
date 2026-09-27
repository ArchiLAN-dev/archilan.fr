<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Exception\MemberDirectMessageException;
use App\Community\Application\Port\MemberDirectMessageInterface;
use App\Community\Application\Support\ModerationForumMessage;

/**
 * Stands in for the bot's direct messages (story 39.3 tests): records what it sends, and can be told to fail.
 */
final class RecordingMemberDirectMessages implements MemberDirectMessageInterface
{
    /** @var list<array{discordUserId: string, message: ModerationForumMessage}> */
    public array $sent = [];

    public ?MemberDirectMessageException $failWith = null;

    public function __construct(private readonly bool $configured = true)
    {
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(string $discordUserId, ModerationForumMessage $message): void
    {
        if (null !== $this->failWith) {
            throw $this->failWith;
        }
        $this->sent[] = ['discordUserId' => $discordUserId, 'message' => $message];
    }
}
