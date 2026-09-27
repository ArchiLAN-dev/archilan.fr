<?php

declare(strict_types=1);

namespace App\Community\Application\Handler;

use App\Community\Application\Exception\ModerationForumDeliveryException;
use App\Community\Application\Message\SyncDiscordBansMessage;
use App\Community\Application\Port\DiscordBan;
use App\Community\Application\Port\DiscordServerSanctionsInterface;
use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Application\Port\ModerationForumInterface;
use App\Community\Application\Query\CommunityAdminIdsQueryInterface;
use App\Community\Application\Service\AccountModerationService;
use App\Community\Application\Support\ModerationForumMessageFactory;
use App\Community\Domain\Entity\DiscordBanNotice;
use App\Community\Domain\Entity\ModerationAction;
use App\Community\Domain\Repository\DiscordBanNoticeRepositoryInterface;
use App\Community\Domain\Repository\ModerationActionRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Applies on the site the bans posed on the Discord server (story 39.7). The site stays the source of the
 * sanctions: a ban from Discord becomes a ban of the site, acted by "Discord", which then goes the usual way
 * (case, direct message, forum). An unban on Discord lifts a ban that came from Discord, never one posed from
 * the site.
 *
 * The bot holds no live connection: the server's ban list is read every five minutes, whole. Unbans are only
 * decided on a list read to its end - a failed read lifts nothing. A ban the site does not apply (no linked
 * account, or an admin's: admins are never sanctioned) is told to the staff forum once.
 *
 * Runs on the scheduler, which never retries: every step is safe to repeat at the next pass.
 */
#[AsMessageHandler]
final readonly class SyncDiscordBansHandler
{
    private const string LIFT_REASON = 'Débanni sur Discord';

    public function __construct(
        private DiscordServerSanctionsInterface $server,
        private MemberModerationGatewayInterface $members,
        private CommunityAdminIdsQueryInterface $admins,
        private ModerationActionRepositoryInterface $actions,
        private AccountModerationService $moderation,
        private DiscordBanNoticeRepositoryInterface $notices,
        private ModerationForumInterface $forum,
        private ModerationForumMessageFactory $forumMessages,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(SyncDiscordBansMessage $message): void
    {
        if (!$this->server->isConfigured()) {
            return;
        }

        try {
            $bans = $this->server->bans();
        } catch (\Throwable $e) {
            $this->logger->warning('moderation_discord_bans.not_read', ['error' => $e->getMessage()]);

            return;
        }

        $fromDiscord = array_values(array_filter($bans, static fn (DiscordBan $ban): bool => !$ban->postedBySite()));
        $authors = [] !== $fromDiscord ? $this->server->banAuthors() : [];
        $adminIds = $this->admins->adminUserIds();
        $told = [];
        foreach ($this->notices->all() as $notice) {
            $told[$notice->getDiscordUserId()] = $notice;
        }

        foreach ($fromDiscord as $ban) {
            try {
                $this->applyBan($ban, $authors[$ban->discordUserId] ?? null, $adminIds, $told);
            } catch (\Throwable $e) {
                $this->logger->warning('moderation_discord_bans.not_applied', ['discordUserId' => $ban->discordUserId, 'error' => $e->getMessage()]);
            }
        }

        $stillBanned = array_fill_keys(array_map(static fn (DiscordBan $ban): string => $ban->discordUserId, $bans), true);
        $this->liftUnbanned($stillBanned);
        $this->forgetGoneNotices($told, $stillBanned);
    }

    /**
     * @param list<string>                    $adminIds
     * @param array<string, DiscordBanNotice> $told
     */
    private function applyBan(DiscordBan $ban, ?string $author, array $adminIds, array $told): void
    {
        $userId = $this->members->userIdForDiscordId($ban->discordUserId);
        if (null === $userId || \in_array($userId, $adminIds, true)) {
            if (!isset($told[$ban->discordUserId])) {
                $this->tellStaff($ban, $author, null !== $userId);
            }

            return;
        }
        if (null !== $this->members->currentState($userId)?->bannedAt) {
            return;
        }

        $reason = sprintf('Ban posé sur Discord%s : %s', null !== $author ? ' par '.$author : '', $ban->reason ?? 'sans raison');
        $this->moderation->ban(ModerationAction::ACTOR_DISCORD, $userId, $reason);
    }

    /**
     * @param array<string, true> $stillBanned
     */
    private function liftUnbanned(array $stillBanned): void
    {
        foreach ($this->members->currentlyBanned() as $member) {
            if (isset($stillBanned[$member->discordId])) {
                continue;
            }
            $latest = $this->actions->forTarget($member->userId, 1)[0] ?? null;
            if (null === $latest || ModerationAction::ACTION_BAN !== $latest->getAction() || ModerationAction::ACTOR_DISCORD !== $latest->getActorId()) {
                continue;
            }
            try {
                $this->moderation->lift(ModerationAction::ACTOR_DISCORD, $member->userId, self::LIFT_REASON);
            } catch (\Throwable $e) {
                $this->logger->warning('moderation_discord_bans.not_lifted', ['userId' => $member->userId, 'error' => $e->getMessage()]);
            }
        }
    }

    private function tellStaff(DiscordBan $ban, ?string $author, bool $admin): void
    {
        if ($this->forum->isConfigured()) {
            try {
                $this->forum->openThread('Discord : '.$ban->username, $this->forumMessages->forUnappliedDiscordBan($ban, $author, $admin));
            } catch (ModerationForumDeliveryException $e) {
                if ($e->transient) {
                    // Not remembered: told at the next pass.
                    throw $e;
                }
                $this->logger->warning('moderation_forum.not_posted', ['discordUserId' => $ban->discordUserId, 'error' => $e->getMessage()]);
            }
        }

        $this->notices->save(DiscordBanNotice::record($ban->discordUserId, $admin ? DiscordBanNotice::REASON_ADMIN : DiscordBanNotice::REASON_UNLINKED, $this->clock->now()));
        $this->notices->flush();
    }

    /**
     * @param array<string, DiscordBanNotice> $told
     * @param array<string, true>             $stillBanned
     */
    private function forgetGoneNotices(array $told, array $stillBanned): void
    {
        $gone = array_diff_key($told, $stillBanned);
        foreach ($gone as $notice) {
            $this->notices->remove($notice);
        }
        if ([] !== $gone) {
            $this->notices->flush();
        }
    }
}
