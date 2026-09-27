<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Exception\DiscordServerSanctionException;
use App\Community\Application\Port\DiscordServerSanctionsInterface;

/**
 * Stands in for the Discord server's bans (story 39.5 tests): records bans and unbans, with how many direct
 * messages had gone out at that moment, and can be told to fail.
 */
final class RecordingDiscordServer implements DiscordServerSanctionsInterface
{
    /** @var list<array{discordUserId: string, reason: string, directMessagesBefore: int}> */
    public array $bans = [];

    /** @var list<string> */
    public array $unbans = [];

    public ?DiscordServerSanctionException $failWith = null;

    public function __construct(
        private readonly ?RecordingMemberDirectMessages $directMessages = null,
        private readonly bool $configured = true,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function ban(string $discordUserId, string $reason): void
    {
        if (null !== $this->failWith) {
            throw $this->failWith;
        }
        $this->bans[] = ['discordUserId' => $discordUserId, 'reason' => $reason, 'directMessagesBefore' => \count($this->directMessages->sent ?? [])];
    }

    public function unban(string $discordUserId): void
    {
        if (null !== $this->failWith) {
            throw $this->failWith;
        }
        $this->unbans[] = $discordUserId;
    }
}
