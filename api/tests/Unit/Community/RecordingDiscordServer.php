<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Exception\DiscordServerSanctionException;
use App\Community\Application\Port\DiscordBan;
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

    /** @var list<array{discordUserId: string, until: string, reason: string}> */
    public array $timeouts = [];

    /** @var list<string> */
    public array $clearedTimeouts = [];

    /** @var list<string> members the server does not have */
    public array $absent = [];

    /** @var list<string> members whose timeout fails as Discord being down */
    public array $failingMembers = [];

    /** @var list<DiscordBan> the server's ban list */
    public array $banList = [];

    public ?DiscordServerSanctionException $failListingWith = null;

    /** @var array<string, string> banned member => who banned them */
    public array $authors = [];

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

    public function timeout(string $discordUserId, \DateTimeImmutable $until, string $reason): bool
    {
        if (null !== $this->failWith) {
            throw $this->failWith;
        }
        if (\in_array($discordUserId, $this->failingMembers, true)) {
            throw new DiscordServerSanctionException('Discord 503', transient: true);
        }
        if (\in_array($discordUserId, $this->absent, true)) {
            return false;
        }
        $this->timeouts[] = ['discordUserId' => $discordUserId, 'until' => $until->format(\DateTimeInterface::ATOM), 'reason' => $reason];

        return true;
    }

    public function clearTimeout(string $discordUserId): void
    {
        if (null !== $this->failWith) {
            throw $this->failWith;
        }
        $this->clearedTimeouts[] = $discordUserId;
    }

    public function bans(): array
    {
        if (null !== $this->failListingWith) {
            throw $this->failListingWith;
        }

        return $this->banList;
    }

    public function banAuthors(): array
    {
        return $this->authors;
    }

    public function unban(string $discordUserId): void
    {
        if (null !== $this->failWith) {
            throw $this->failWith;
        }
        $this->unbans[] = $discordUserId;
    }
}
