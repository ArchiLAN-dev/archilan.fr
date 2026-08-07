<?php

declare(strict_types=1);

namespace App\Identity\Application\Service;

use App\Identity\Domain\Entity\AdminUserActionAudit;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\AdminUserActionAuditRepositoryInterface;
use App\Identity\Domain\Repository\RefreshTokenRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\PersonalRuns\Application\Service\PersonalRunDrafts;
use App\Sessions\Application\Command\ForceEndSessionCommand;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The closed list of actions an admin may apply to another member's objects from their sheet
 * (story 36.6).
 *
 * Closed on purpose: "acting on their objects" has no natural end, so each action here answers a real
 * operational case - a run left running by an absent owner (issue #387), a compromised account, a lost
 * confirmation email. No impersonation is involved: the admin acts as themselves, and every action is
 * attributed to them.
 *
 * Outcomes are returned as strings the controller maps to status codes, following the same contract as
 * AccountModerationService.
 */
final readonly class AdminUserActions
{
    public function __construct(
        private UserRepositoryInterface $users,
        private RefreshTokenRepositoryInterface $refreshTokens,
        private AdminUserActionAuditRepositoryInterface $audits,
        private PersonalRunDrafts $runs,
        private ForceEndSessionCommand $forceEnd,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Cuts every active session of a member - the immediate response to a compromised account.
     *
     * @return 'ok'|'not_found'|'forbidden'
     */
    public function revokeSessions(string $adminId, string $targetUserId): string
    {
        $target = $this->loadActionable($adminId, $targetUserId);
        if (is_string($target)) {
            return $target;
        }

        $this->refreshTokens->revokeAllForUser($target->getId());
        $this->trace($target->getId(), $adminId, AdminUserActionAudit::ACTION_REVOKE_SESSIONS);

        return 'ok';
    }

    /**
     * Marks the email verified on the member's behalf, for the account stuck behind a lost confirmation
     * mail.
     *
     * @return 'ok'|'already'|'not_found'|'forbidden'
     */
    public function verifyEmail(string $adminId, string $targetUserId): string
    {
        $target = $this->loadActionable($adminId, $targetUserId);
        if (is_string($target)) {
            return $target;
        }

        // confirmEmail() is idempotent in the domain. Reporting "already" rather than writing a trace
        // keeps the journal free of entries for actions that changed nothing.
        if ($target->isEmailVerified()) {
            return 'already';
        }

        $target->confirmEmail($this->clock->now());
        $this->users->flush();
        $this->trace($target->getId(), $adminId, AdminUserActionAudit::ACTION_VERIFY_EMAIL);

        return 'ok';
    }

    /**
     * Ends the live session of one of the member's personal runs.
     *
     * Delegates to {@see ForceEndSessionCommand}, which stops the session, tells the runner, queues the
     * archive job and writes the run audit row. Re-implementing any of that here would lose three of
     * those four effects - and PersonalRunLifecycle::stop() is not an option, since it requires the
     * caller to own the run.
     *
     * @return 'ok'|'not_found'|'forbidden'|'not_running'
     */
    public function stopRun(string $adminId, string $targetUserId, string $runId): string
    {
        $target = $this->loadActionable($adminId, $targetUserId);
        if (is_string($target)) {
            return $target;
        }

        // The run must belong to the member whose sheet we are on: an admin acting from someone's sheet
        // must not be able to reach an arbitrary run by id.
        $sessionId = $this->activeSessionOfOwnedRun($target->getId(), $runId);
        if (null === $sessionId) {
            return 'not_running';
        }

        try {
            $this->forceEnd->execute($sessionId, $adminId);
        } catch (\Throwable $e) {
            $this->logger->warning('admin.stop_run_failed', [
                'runId' => $runId,
                'sessionId' => $sessionId,
                'adminId' => $adminId,
                'error' => $e->getMessage(),
            ]);

            return 'not_running';
        }

        return 'ok';
    }

    /**
     * Shared guard: the account must exist, and an admin never applies these to themselves - the only
     * way back would be another admin, and there may be none.
     *
     * @return User|'not_found'|'forbidden'
     */
    private function loadActionable(string $adminId, string $targetUserId): User|string
    {
        if ($adminId === $targetUserId) {
            return 'forbidden';
        }

        $target = $this->users->findById($targetUserId);
        if (!$target instanceof User || $target->isDeleted()) {
            return 'not_found';
        }

        return $target;
    }

    /**
     * The live session id of a run the member owns, or null when there is none to stop.
     */
    private function activeSessionOfOwnedRun(string $ownerId, string $runId): ?string
    {
        foreach ($this->runs->listMine($ownerId)['owned'] as $run) {
            if (($run['id'] ?? null) !== $runId) {
                continue;
            }
            $sessionId = $run['sessionId'] ?? null;

            return is_string($sessionId) && '' !== $sessionId ? $sessionId : null;
        }

        return null;
    }

    private function trace(string $targetUserId, string $adminId, string $action): void
    {
        $this->audits->save(AdminUserActionAudit::record($targetUserId, $adminId, $action, $this->clock->now()));
        $this->logger->info('admin.user_action', ['targetUserId' => $targetUserId, 'adminId' => $adminId, 'action' => $action]);
    }
}
