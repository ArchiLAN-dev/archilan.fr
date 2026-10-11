<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Community;

use App\Community\Application\Exception\MemberDirectMessageException;
use App\Community\Application\Port\NotificationDirectMessageInterface;
use App\Community\Application\Support\WebPushMessage;

/**
 * Story 40.6: the bot's direct messages for the tests - records what was sent to whom, and can be switched off or
 * made to fail (transient or not).
 */
final class SpyNotificationDirectMessages implements NotificationDirectMessageInterface
{
    public bool $configured = true;
    public ?bool $failTransient = null;
    /** @var list<array{discordUserId: string, message: WebPushMessage}> */
    public array $sent = [];

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(string $discordUserId, WebPushMessage $message): void
    {
        if (null !== $this->failTransient) {
            throw new MemberDirectMessageException('Discord said no.', null, $this->failTransient);
        }
        $this->sent[] = ['discordUserId' => $discordUserId, 'message' => $message];
    }
}
