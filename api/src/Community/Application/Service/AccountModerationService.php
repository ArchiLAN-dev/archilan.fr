<?php

declare(strict_types=1);

namespace App\Community\Application\Service;

use App\Community\Application\Message\PostModerationActionToForumJob;
use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Application\Query\CommunityAdminIdsQueryInterface;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Support\Notifier;
use App\Community\Domain\Entity\ModerationAction;
use App\Community\Domain\Entity\Notification;
use App\Community\Domain\Repository\ContentReportRepositoryInterface;
use App\Community\Domain\Repository\ModerationActionRepositoryInterface;
use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Admin actions on a member's account (story 30.29): warn / suspend / ban / lift, and a note for the staff
 * only (story 39.10). Suspend & ban delegate
 * the access-state change to Identity through {@see MemberModerationGatewayInterface}, then audit-log it and
 * auto-resolve the account's open profile reports. Warn only notifies + logs (no access change).
 */
final readonly class AccountModerationService
{
    public function __construct(
        private MemberModerationGatewayInterface $gateway,
        private ModerationActionRepositoryInterface $actions,
        private ContentReportRepositoryInterface $reports,
        private CommunityUserDirectoryQueryInterface $directory,
        private CommunityAdminIdsQueryInterface $admins,
        private Notifier $notifier,
        private ClockInterface $clock,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return string 'ok' | 'not_found' | 'invalid' | 'forbidden'
     */
    public function warn(string $adminId, string $targetUserId, string $reason, ?string $relatedReportId = null): string
    {
        $reason = trim($reason);
        if ('' === $reason) {
            return 'invalid';
        }
        if (null !== ($denied = $this->guard($adminId, $targetUserId))) {
            return $denied;
        }
        // Warn doesn't change access, so confirm the account exists before logging/notifying.
        if (!isset($this->directory->cards([$targetUserId])[$targetUserId])) {
            return 'not_found';
        }

        $action = $this->newAction($adminId, $targetUserId, ModerationAction::ACTION_WARN, $reason, $relatedReportId);
        $this->actions->save($action);
        $this->notifier->notify($targetUserId, Notification::TYPE_MODERATION_WARNING, ['reason' => $reason]);
        $this->mirrorInForum($action);

        return 'ok';
    }

    /**
     * Story 39.10: a note for the staff only - recorded in the history and the member's case, posted in the
     * staff forum, never told to the member (no notification, no direct message) and changing nothing for them.
     *
     * @return string 'ok' | 'not_found' | 'invalid' | 'forbidden'
     */
    public function note(string $adminId, string $targetUserId, string $reason): string
    {
        $reason = trim($reason);
        if ('' === $reason) {
            return 'invalid';
        }
        if (null !== ($denied = $this->guard($adminId, $targetUserId))) {
            return $denied;
        }
        if (!isset($this->directory->cards([$targetUserId])[$targetUserId])) {
            return 'not_found';
        }

        $action = $this->newAction($adminId, $targetUserId, ModerationAction::ACTION_NOTE, $reason, null);
        $this->actions->save($action);
        $this->mirrorInForum($action);

        return 'ok';
    }

    /**
     * @return string 'ok' | 'not_found' | 'invalid' | 'forbidden'
     */
    public function suspend(string $adminId, string $targetUserId, \DateTimeImmutable $until, string $reason, ?string $relatedReportId = null): string
    {
        $reason = trim($reason);
        if ('' === $reason || $until <= $this->clock->now()) {
            return 'invalid';
        }
        if (null !== ($denied = $this->guard($adminId, $targetUserId))) {
            return $denied;
        }

        $action = $this->newAction($adminId, $targetUserId, ModerationAction::ACTION_SUSPEND, $reason, $relatedReportId);

        return $this->afterCommit($action, $this->transactionally(fn (): string => $this->gateway->suspendUntil($targetUserId, $until, $reason)
            ? $this->commitAction($action, autoResolve: true)
            : 'not_found'));
    }

    /**
     * @return string 'ok' | 'not_found' | 'invalid' | 'forbidden'
     */
    public function ban(string $adminId, string $targetUserId, string $reason, ?string $relatedReportId = null): string
    {
        $reason = trim($reason);
        if ('' === $reason) {
            return 'invalid';
        }
        if (null !== ($denied = $this->guard($adminId, $targetUserId))) {
            return $denied;
        }

        $action = $this->newAction($adminId, $targetUserId, ModerationAction::ACTION_BAN, $reason, $relatedReportId);

        return $this->afterCommit($action, $this->transactionally(fn (): string => $this->gateway->ban($targetUserId, $reason)
            ? $this->commitAction($action, autoResolve: true)
            : 'not_found'));
    }

    /**
     * @return string 'ok' | 'not_found' | 'forbidden'
     */
    public function lift(string $adminId, string $targetUserId, string $reason): string
    {
        if (null !== ($denied = $this->guard($adminId, $targetUserId))) {
            return $denied;
        }

        $trimmed = trim($reason);
        $logReason = '' === $trimmed ? 'Levée de la sanction' : $trimmed;

        $action = $this->newAction($adminId, $targetUserId, ModerationAction::ACTION_LIFT, $logReason, null);

        return $this->afterCommit($action, $this->transactionally(fn (): string => $this->gateway->lift($targetUserId)
            ? $this->commitAction($action, autoResolve: false)
            : 'not_found'));
    }

    /**
     * Refuse moderating yourself or another admin (admins are not subject to these tools - mirrors the guard
     * on User::promoteToMember/demoteToUser).
     */
    private function guard(string $adminId, string $targetUserId): ?string
    {
        if ($adminId === $targetUserId) {
            return 'forbidden';
        }
        if (in_array($targetUserId, $this->admins->adminUserIds(), true)) {
            return 'forbidden';
        }

        return null;
    }

    /**
     * Action history for one account, most recent first.
     *
     * @return list<array{id: string, action: string, reason: string, createdAt: string, actorId: string, relatedReportId: string|null, discordDm: string|null, discordServer: string|null}>
     */
    public function history(string $targetUserId, int $limit = 50): array
    {
        $items = [];
        foreach ($this->actions->forTarget($targetUserId, $limit) as $action) {
            $items[] = [
                'id' => $action->getId(),
                'action' => $action->getAction(),
                'reason' => $action->getReason(),
                'createdAt' => $action->getCreatedAt()->format(\DateTimeInterface::ATOM),
                'actorId' => $action->getActorId(),
                'relatedReportId' => $action->getRelatedReportId(),
                // Stories 39.4 and 39.5: how Discord took it, null until the async job has run.
                'discordDm' => $action->getDiscordDmStatus(),
                'discordServer' => $action->getDiscordServerStatus(),
            ];
        }

        return $items;
    }

    /**
     * Run the write closure inside one connection-level transaction so the Identity state change, the audit
     * row and the report auto-resolution commit together or not at all (story 30.29). All repos share the EM,
     * so the transaction opened here also wraps the gateway's `User` flush.
     *
     * @param \Closure(): string $work
     */
    private function transactionally(\Closure $work): string
    {
        $this->actions->beginTransaction();
        try {
            $result = $work();
            $this->actions->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->actions->rollBack();

            throw $e;
        }
    }

    /** Append the audit row and (for suspend/ban) auto-resolve the open reports, within the open transaction. */
    private function commitAction(ModerationAction $action, bool $autoResolve): string
    {
        $this->actions->save($action);
        if ($autoResolve) {
            $this->autoResolve($action->getTargetUserId(), $action->getActorId());
        }

        return 'ok';
    }

    /** Built before the transaction so its id is known once the transaction has committed. */
    private function newAction(string $adminId, string $targetUserId, string $action, string $reason, ?string $relatedReportId): ModerationAction
    {
        return ModerationAction::create(
            $adminId,
            $targetUserId,
            $action,
            mb_substr($reason, 0, 500),
            $this->clock->now(),
            $relatedReportId,
        );
    }

    /** Pass the transaction's result through, mirroring the sanction in the staff forum when it committed. */
    private function afterCommit(ModerationAction $action, string $result): string
    {
        if ('ok' === $result) {
            $this->mirrorInForum($action);
        }

        return $result;
    }

    /**
     * Story 39.1: the member's case and its staff forum post, asynchronously and after the commit. A bus that
     * cannot take the job never undoes the sanction: it is logged, and the case catches up with the next one.
     */
    private function mirrorInForum(ModerationAction $action): void
    {
        try {
            $this->bus->dispatch(new PostModerationActionToForumJob($action->getId()));
        } catch (\Throwable $e) {
            $this->logger->error('moderation_forum.dispatch_failed', [
                'actionId' => $action->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** Resolve the account's open profile reports so it leaves the "à examiner" list (story 30.29 AC-6). */
    private function autoResolve(string $targetUserId, string $adminId): void
    {
        $pending = $this->reports->pendingForProfileTarget($targetUserId);
        if ([] === $pending) {
            return;
        }

        $now = $this->clock->now();
        foreach ($pending as $report) {
            $report->resolve($adminId, $now);
        }
        $this->reports->flush();
    }
}
