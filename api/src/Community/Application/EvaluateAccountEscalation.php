<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Notification;
use App\Community\Domain\ReportSeverity;

/**
 * Notifies admins when a reported account's weighted score *crosses* the escalation threshold (story 30.28).
 *
 * Debounce without persistent state: the score before this report = current score - this report's weight, so
 * we notify exactly on the below→above transition. Reports that land while already above don't re-notify;
 * weight-0 ("Autre/Autre") reports never move the score, so they never escalate or notify.
 */
final readonly class EvaluateAccountEscalation
{
    public function __construct(
        private AccountReportScoreQueryInterface $scores,
        private CommunityAdminIdsQueryInterface $admins,
        private CommunityUserDirectoryQueryInterface $directory,
        private Notifier $notifier,
        private int $escalationThreshold,
    ) {
    }

    public function afterProfileReport(string $accountUserId, int $addedWeight): void
    {
        if ($addedWeight <= 0) {
            return;
        }

        $current = ReportSeverity::sum($this->scores->unresolvedProblemsForAccount($accountUserId));
        $before = $current - $addedWeight;
        if ($before >= $this->escalationThreshold || $current < $this->escalationThreshold) {
            return;
        }

        $card = $this->directory->cards([$accountUserId])[$accountUserId] ?? null;
        $payload = [
            'accountId' => $accountUserId,
            'slug' => $card['slug'] ?? null,
            'displayName' => $card['displayName'] ?? null,
            'score' => $current,
        ];

        foreach ($this->admins->adminUserIds() as $adminId) {
            $this->notifier->notify($adminId, Notification::TYPE_ACCOUNT_FLAGGED, $payload);
        }
    }
}
