<?php

declare(strict_types=1);

namespace App\Community\Application;

/**
 * Reads the unresolved *profile* reports per reported account (story 30.28), so the weighted score can be
 * computed in the domain ({@see \App\Community\Domain\ReportSeverity}). Comment reports stay content-level
 * (hide/restore) and are out of account escalation by design.
 */
interface AccountReportScoreQueryInterface
{
    /**
     * Problem keys of unresolved profile-target reports against one account.
     *
     * @return list<string>
     */
    public function unresolvedProblemsForAccount(string $accountUserId): array;

    /**
     * Problem keys of unresolved profile-target reports, grouped by reported account user id.
     *
     * @return array<string, list<string>>
     */
    public function unresolvedProblemsByAccount(): array;
}
