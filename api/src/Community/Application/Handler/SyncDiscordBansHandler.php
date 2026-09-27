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
use App\Community\Domain\Repository\DiscordBanBaselineRepositoryInterface;
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
 * Story 39.9: the first pass after activation only shows the staff the bans already there, one post each, and
 * applies nothing; they are never applied afterwards either. A lift the staff made on the site and Discord has
 * not applied yet is never undone by a ban still listed.
 *
 * Every step is safe to repeat at the next pass.
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
        private DiscordBanBaselineRepositoryInterface $baseline,
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
        $authors = [] !== $fromDiscord ? $this->authors() : [];
        $told = [];
        foreach ($this->notices->all() as $notice) {
            $told[$notice->getDiscordUserId()] = $notice;
        }

        if (!$this->baseline->isTaken()) {
            $this->takeBaseline($fromDiscord, $authors, $told);

            return;
        }

        $adminIds = $this->admins->adminUserIds();
        foreach ($fromDiscord as $ban) {
            if (isset($told[$ban->discordUserId])) {
                continue;
            }
            try {
                $this->applyBan($ban, $authors[$ban->discordUserId] ?? null, $adminIds);
            } catch (\Throwable $e) {
                $this->logger->warning('moderation_discord_bans.not_applied', ['discordUserId' => $ban->discordUserId, 'error' => $e->getMessage()]);
            }
        }

        $stillBanned = array_fill_keys(array_map(static fn (DiscordBan $ban): string => $ban->discordUserId, $bans), true);
        $this->liftUnbanned($stillBanned);
        $this->forgetGoneNotices($told, $stillBanned);
    }

    /**
     * Story 39.9: the bans already on the server when the synchronisation is switched on are shown to the staff,
     * one post each, and never applied. Taken again at the next pass if one of them could not be told.
     *
     * @param list<DiscordBan>                $bans
     * @param array<string, string>           $authors
     * @param array<string, DiscordBanNotice> $told
     */
    private function takeBaseline(array $bans, array $authors, array $told): void
    {
        $complete = true;
        foreach ($bans as $ban) {
            if (isset($told[$ban->discordUserId])) {
                continue;
            }
            try {
                $this->tellStaff($ban, $authors[$ban->discordUserId] ?? null, DiscordBanNotice::REASON_PREEXISTING, $this->members->userIdForDiscordId($ban->discordUserId));
            } catch (\Throwable $e) {
                $complete = false;
                $this->logger->warning('moderation_discord_bans.not_told', ['discordUserId' => $ban->discordUserId, 'error' => $e->getMessage()]);
            }
        }

        if ($complete) {
            $this->baseline->markTaken($this->clock->now());
        }
    }

    /**
     * @param list<string> $adminIds
     */
    private function applyBan(DiscordBan $ban, ?string $author, array $adminIds): void
    {
        $userId = $this->members->userIdForDiscordId($ban->discordUserId);
        if (null === $userId || \in_array($userId, $adminIds, true)) {
            $this->tellStaff($ban, $author, null === $userId ? DiscordBanNotice::REASON_UNLINKED : DiscordBanNotice::REASON_ADMIN, $userId);

            return;
        }
        if (null !== $this->members->currentState($userId)?->bannedAt) {
            return;
        }
        // Story 39.9: the staff lifted on the site and Discord has not unbanned yet (refused, or still retrying).
        // The ban still listed is the old one: banning again would undo the staff's decision.
        $latest = $this->latestBanOrLift($userId);
        if (null !== $latest && ModerationAction::ACTION_LIFT === $latest->getAction()
            && ModerationAction::SERVER_LIFTED !== $latest->getDiscordServerStatus()) {
            $this->logger->warning('moderation_discord_bans.lift_pending', ['userId' => $userId, 'actionId' => $latest->getId()]);

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
            $latest = $this->latestBanOrLift($member->userId);
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

    /** The member's latest ban or lift, a warning or a suspension in between not counting (story 39.9). */
    private function latestBanOrLift(string $userId): ?ModerationAction
    {
        foreach ($this->actions->forTarget($userId, 50) as $action) {
            if (\in_array($action->getAction(), [ModerationAction::ACTION_BAN, ModerationAction::ACTION_LIFT], true)) {
                return $action;
            }
        }

        return null;
    }

    /**
     * Without "View Audit Log" the adapter answers an empty list; anything else unexpected must not stop the
     * pass either (story 39.9).
     *
     * @return array<string, string>
     */
    private function authors(): array
    {
        try {
            return $this->server->banAuthors();
        } catch (\Throwable $e) {
            $this->logger->warning('moderation_discord_bans.authors_not_read', ['error' => $e->getMessage()]);

            return [];
        }
    }

    /**
     * @param string $reason one of the {@see DiscordBanNotice} REASON_ values
     */
    private function tellStaff(DiscordBan $ban, ?string $author, string $reason, ?string $siteUserId): void
    {
        if ($this->forum->isConfigured()) {
            try {
                $this->forum->openThread('Discord : '.$ban->username, $this->forumMessages->forUnappliedDiscordBan($ban, $author, $reason, $siteUserId));
            } catch (ModerationForumDeliveryException $e) {
                if ($e->transient) {
                    // Not remembered: told at the next pass.
                    throw $e;
                }
                $this->logger->warning('moderation_forum.not_posted', ['discordUserId' => $ban->discordUserId, 'error' => $e->getMessage()]);
            }
        }

        $this->notices->save(DiscordBanNotice::record($ban->discordUserId, $reason, $this->clock->now()));
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
