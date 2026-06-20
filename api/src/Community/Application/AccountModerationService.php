<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\ContentReportRepositoryInterface;
use App\Community\Domain\ModerationAction;
use App\Community\Domain\ModerationActionRepositoryInterface;
use App\Community\Domain\Notification;

/**
 * Admin actions on a member's account (story 30.29): warn / suspend / ban / lift. Suspend & ban delegate
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
        private Notifier $notifier,
    ) {
    }

    /**
     * @return string 'ok' | 'not_found' | 'invalid'
     */
    public function warn(string $adminId, string $targetUserId, string $reason, ?string $relatedReportId = null): string
    {
        $reason = trim($reason);
        if ('' === $reason) {
            return 'invalid';
        }
        // Warn doesn't change access, so confirm the account exists before logging/notifying.
        if (!isset($this->directory->cards([$targetUserId])[$targetUserId])) {
            return 'not_found';
        }

        $this->log($adminId, $targetUserId, ModerationAction::ACTION_WARN, $reason, $relatedReportId);
        $this->notifier->notify($targetUserId, Notification::TYPE_MODERATION_WARNING, ['reason' => $reason]);

        return 'ok';
    }

    /**
     * @return string 'ok' | 'not_found' | 'invalid'
     */
    public function suspend(string $adminId, string $targetUserId, \DateTimeImmutable $until, string $reason, ?string $relatedReportId = null): string
    {
        $reason = trim($reason);
        if ('' === $reason || $until <= new \DateTimeImmutable()) {
            return 'invalid';
        }
        if (!$this->gateway->suspendUntil($targetUserId, $until, $reason)) {
            return 'not_found';
        }

        $this->log($adminId, $targetUserId, ModerationAction::ACTION_SUSPEND, $reason, $relatedReportId);
        $this->autoResolve($targetUserId, $adminId);

        return 'ok';
    }

    /**
     * @return string 'ok' | 'not_found' | 'invalid'
     */
    public function ban(string $adminId, string $targetUserId, string $reason, ?string $relatedReportId = null): string
    {
        $reason = trim($reason);
        if ('' === $reason) {
            return 'invalid';
        }
        if (!$this->gateway->ban($targetUserId, $reason)) {
            return 'not_found';
        }

        $this->log($adminId, $targetUserId, ModerationAction::ACTION_BAN, $reason, $relatedReportId);
        $this->autoResolve($targetUserId, $adminId);

        return 'ok';
    }

    /**
     * @return string 'ok' | 'not_found'
     */
    public function lift(string $adminId, string $targetUserId, string $reason): string
    {
        if (!$this->gateway->lift($targetUserId)) {
            return 'not_found';
        }

        $trimmed = trim($reason);
        $this->log($adminId, $targetUserId, ModerationAction::ACTION_LIFT, '' === $trimmed ? 'Levée de la sanction' : $trimmed, null);

        return 'ok';
    }

    /**
     * Action history for one account, most recent first.
     *
     * @return list<array{id: string, action: string, reason: string, createdAt: string, actorId: string, relatedReportId: string|null}>
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
            ];
        }

        return $items;
    }

    private function log(string $adminId, string $targetUserId, string $action, string $reason, ?string $relatedReportId): void
    {
        $this->actions->save(ModerationAction::create(
            $adminId,
            $targetUserId,
            $action,
            mb_substr($reason, 0, 500),
            new \DateTimeImmutable(),
            $relatedReportId,
        ));
    }

    /** Resolve the account's open profile reports so it leaves the "à examiner" list (story 30.29 AC-6). */
    private function autoResolve(string $targetUserId, string $adminId): void
    {
        $pending = $this->reports->pendingForProfileTarget($targetUserId);
        if ([] === $pending) {
            return;
        }

        $now = new \DateTimeImmutable();
        foreach ($pending as $report) {
            $report->resolve($adminId, $now);
        }
        $this->reports->flush();
    }
}
