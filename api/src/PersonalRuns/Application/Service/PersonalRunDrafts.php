<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Service;

use App\Community\Application\Query\CommunityLevelQuery;
use App\Community\Application\Query\CommunityPresenceQueryInterface;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Identity\Application\Support\ValidationErrors;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Membership\Application\Query\ActiveMembershipQueryInterface;
use App\PersonalRuns\Application\Port\RunGameAssignmentInterface;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Shared\Application\Support\ArchipelagoConnectionUri;
use Psr\Clock\ClockInterface;

final readonly class PersonalRunDrafts
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private UserRepositoryInterface $users,
        private SessionRepositoryInterface $sessions,
        private CommunityUserDirectoryQueryInterface $directory,
        private ActiveMembershipQueryInterface $memberships,
        private CommunityLevelQuery $levels,
        private CommunityPresenceQueryInterface $presence,
        private RunGameAssignmentInterface $gameAssignment,
        private ClockInterface $clock,
        private string $siteUrl,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{run: array<string, mixed>|null, errors: array<string, list<string>>}
     */
    public function create(string $ownerId, array $input): array
    {
        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
        $errors = new ValidationErrors();

        if ('' === $title) {
            $errors->add('title', 'Le titre est requis.');
        } elseif (mb_strlen($title) > 80) {
            $errors->add('title', 'Le titre ne peut pas dépasser 80 caractères.');
        }

        // Story 17.23: an optional game preselects the creator's first slot, so the "create a run
        // with this game" button on a game page is one call rather than two.
        $gameId = is_string($input['gameId'] ?? null) ? trim($input['gameId']) : '';

        // Validated BEFORE anything is created: a game that cannot be added must not leave an empty
        // run behind. The rule itself lives in PersonalRunGameSelection, so this path and the normal
        // selection path can never disagree about what is addable.
        if ('' !== $gameId) {
            foreach ($this->gameAssignment->reasonsGameCannotBeAdded($gameId) as $reason) {
                $errors->add('gameId', $reason);
            }
        }

        $errs = $errors->toArray();
        if ([] !== $errs) {
            return ['run' => null, 'errors' => $errs];
        }

        $run = Run::create($ownerId, $title, $this->clock->now());
        $this->runs->save($run);

        if ('' !== $gameId) {
            $this->gameAssignment->assignGameToCreator($run->getId(), $ownerId, $gameId);
        }

        return ['run' => $this->payload($run, $ownerId, []), 'errors' => []];
    }

    /**
     * Renames a run the caller owns (story 17.24). Same title rules as creation, so a run cannot be
     * renamed into something the creation form would have rejected.
     *
     * @param array<string, mixed> $input
     *
     * @return array{found: bool, authorized: bool, run: array<string, mixed>|null, errors: array<string, list<string>>}
     */
    public function rename(string $runId, string $callerId, array $input): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return ['found' => false, 'authorized' => false, 'run' => null, 'errors' => []];
        }

        if (!$run->isOwnedBy($callerId)) {
            return ['found' => true, 'authorized' => false, 'run' => null, 'errors' => []];
        }

        $title = is_string($input['title'] ?? null) ? trim($input['title']) : '';
        $errors = new ValidationErrors();
        if ('' === $title) {
            $errors->add('title', 'Le titre est requis.');
        } elseif (mb_strlen($title) > 80) {
            $errors->add('title', 'Le titre ne peut pas dépasser 80 caractères.');
        }

        $errs = $errors->toArray();
        if ([] !== $errs) {
            return ['found' => true, 'authorized' => true, 'run' => null, 'errors' => $errs];
        }

        $run->rename($title, $this->clock->now());
        $this->runs->flush();

        return [
            'found' => true,
            'authorized' => true,
            'run' => $this->payload($run, $callerId, $this->getParticipants($run->getId())),
            'errors' => [],
        ];
    }

    /**
     * Runs visible in the user's "Mes parties" space: the ones they own and the ones
     * they joined (participant but not owner).
     *
     * @return array{owned: list<array<string, mixed>>, joined: list<array<string, mixed>>}
     */
    public function listMine(string $userId): array
    {
        $owned = array_map(
            fn (Run $run): array => $this->payload($run, $userId, []),
            $this->runs->findByOwnerId($userId),
        );
        $joined = array_map(
            fn (Run $run): array => $this->payload($run, $userId, []),
            $this->runs->findJoinedByUserId($userId),
        );

        return ['owned' => $owned, 'joined' => $joined];
    }

    /**
     * @return array{found: bool, authorized: bool, payload: array<string, mixed>|null}
     */
    public function get(string $runId, string $callerId): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return ['found' => false, 'authorized' => false, 'payload' => null];
        }

        $participants = $this->getParticipants($run->getId());
        $isOwner = $run->isOwnedBy($callerId);
        $isParticipant = in_array($callerId, array_column($participants, 'userId'), true);

        if (!$isOwner && !$isParticipant) {
            return ['found' => true, 'authorized' => false, 'payload' => null];
        }

        return [
            'found' => true,
            'authorized' => true,
            'payload' => $this->payload($run, $callerId, $participants),
        ];
    }

    /**
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null}
     */
    public function cancel(string $runId, string $callerId): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return ['found' => false, 'authorized' => false, 'blocked' => false, 'blockReason' => null];
        }

        if (!$run->isOwnedBy($callerId)) {
            return ['found' => true, 'authorized' => false, 'blocked' => false, 'blockReason' => null];
        }

        if (in_array($run->getStatus(), Run::ACTIVE_STATUSES, true)) {
            return ['found' => true, 'authorized' => true, 'blocked' => true, 'blockReason' => 'run_active'];
        }

        if (!in_array($run->getStatus(), [Run::STATUS_DRAFT, Run::STATUS_IDLE], true)) {
            return ['found' => true, 'authorized' => true, 'blocked' => true, 'blockReason' => 'run_not_deletable'];
        }

        $run->cancel($this->clock->now());
        $this->runs->flush();

        return ['found' => true, 'authorized' => true, 'blocked' => false, 'blockReason' => null];
    }

    /**
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null}
     */
    public function archive(string $runId, string $callerId): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return ['found' => false, 'authorized' => false, 'blocked' => false, 'blockReason' => null];
        }

        if (!$run->isOwnedBy($callerId)) {
            return ['found' => true, 'authorized' => false, 'blocked' => false, 'blockReason' => null];
        }

        $archivable = [
            Run::STATUS_DRAFT,
            Run::STATUS_IDLE,
            Run::STATUS_STARTING,
        ];

        if (!in_array($run->getStatus(), $archivable, true)) {
            return ['found' => true, 'authorized' => true, 'blocked' => true, 'blockReason' => 'run_not_archivable'];
        }

        $run->cancel($this->clock->now());
        $this->runs->flush();

        return ['found' => true, 'authorized' => true, 'blocked' => false, 'blockReason' => null];
    }

    /**
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null}
     */
    public function unarchive(string $runId, string $callerId): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return ['found' => false, 'authorized' => false, 'blocked' => false, 'blockReason' => null];
        }

        if (!$run->isOwnedBy($callerId)) {
            return ['found' => true, 'authorized' => false, 'blocked' => false, 'blockReason' => null];
        }

        if (Run::STATUS_CANCELLED !== $run->getStatus()) {
            return ['found' => true, 'authorized' => true, 'blocked' => true, 'blockReason' => 'run_not_archived'];
        }

        $run->unarchive($this->clock->now());
        $this->runs->flush();

        return ['found' => true, 'authorized' => true, 'blocked' => false, 'blockReason' => null];
    }

    /**
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null}
     */
    public function hardDelete(string $runId, string $callerId): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return ['found' => false, 'authorized' => false, 'blocked' => false, 'blockReason' => null];
        }

        if (!$run->isOwnedBy($callerId)) {
            return ['found' => true, 'authorized' => false, 'blocked' => false, 'blockReason' => null];
        }

        $activeStatuses = [Run::STATUS_STARTING, Run::STATUS_ACTIVE, Run::STATUS_STOPPING, Run::STATUS_RESTARTING];
        if (in_array($run->getStatus(), $activeStatuses, true)) {
            return ['found' => true, 'authorized' => true, 'blocked' => true, 'blockReason' => 'run_active'];
        }

        if (Run::STATUS_COMPLETED === $run->getStatus()) {
            return ['found' => true, 'authorized' => true, 'blocked' => true, 'blockReason' => 'run_not_deletable'];
        }

        $this->participants->deleteByRunId($run->getId());
        $this->runs->delete($run);

        return ['found' => true, 'authorized' => true, 'blocked' => false, 'blockReason' => null];
    }

    /**
     * @return array{found: bool, authorized: bool, blocked: bool, inviteToken: string|null, inviteUrl: string|null}
     */
    public function regenerateToken(string $runId, string $callerId): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return ['found' => false, 'authorized' => false, 'blocked' => false, 'inviteToken' => null, 'inviteUrl' => null];
        }

        if (!$run->isOwnedBy($callerId)) {
            return ['found' => true, 'authorized' => false, 'blocked' => false, 'inviteToken' => null, 'inviteUrl' => null];
        }

        // A finished or cancelled run is read-only: no new invite link (#338).
        if ($run->isTerminal()) {
            return ['found' => true, 'authorized' => true, 'blocked' => true, 'inviteToken' => null, 'inviteUrl' => null];
        }

        $run->regenerateInviteToken($this->clock->now());
        $this->runs->flush();

        return [
            'found' => true,
            'authorized' => true,
            'blocked' => false,
            'inviteToken' => $run->getInviteToken(),
            'inviteUrl' => $this->siteUrl.'/runs/join/'.$run->getInviteToken(),
        ];
    }

    /**
     * @return array{status: 'not_found'|'ok', payload: array<string, mixed>|null}
     */
    public function joinByToken(string $inviteToken, string $callerId): array
    {
        $run = $this->runs->findByInviteToken($inviteToken);

        if (!$run instanceof Run || Run::STATUS_CANCELLED === $run->getStatus()) {
            return ['status' => 'not_found', 'payload' => null];
        }

        if (!$run->isOwnedBy($callerId)) {
            $existing = $this->participants->findByRunAndUser($run->getId(), $callerId);

            if (!$existing instanceof RunParticipant) {
                $participant = RunParticipant::create($run->getId(), $callerId, $this->clock->now());
                $this->participants->save($participant);
            }
        }

        $participants = $this->getParticipants($run->getId());

        return ['status' => 'ok', 'payload' => $this->payload($run, $callerId, $participants)];
    }

    /**
     * @return array{title: string, ownerName: string|null, participantCount: int, status: string}|null
     */
    public function previewByToken(string $inviteToken): ?array
    {
        $run = $this->runs->findByInviteToken($inviteToken);

        if (!$run instanceof Run || Run::STATUS_CANCELLED === $run->getStatus()) {
            return null;
        }

        $participantCount = $this->participants->countByRunId($run->getId());
        $owner = $this->users->findById($run->getOwnerId());
        $ownerName = $owner instanceof User ? $owner->getDisplayName() : null;

        return [
            'title' => $run->getTitle(),
            'ownerName' => $ownerName,
            'participantCount' => $participantCount,
            'status' => $run->getStatus(),
        ];
    }

    /**
     * @return list<array{userId: string, slug: string|null, displayName: string|null, avatarUrl: string|null, joinedAt: string, slotCount: int, isMember: bool, isAdmin: bool, level: int, playing: bool}>
     */
    private function getParticipants(string $runId): array
    {
        $participants = $this->participants->findByRunId($runId);

        if ([] === $participants) {
            return [];
        }

        $userIds = array_values(array_unique(array_map(static fn (RunParticipant $p) => $p->getUserId(), $participants)));
        $users = $this->users->findByIds($userIds);

        /** @var array<string, User> $usersById */
        $usersById = [];
        foreach ($users as $user) {
            $usersById[$user->getId()] = $user;
        }

        // Community cards provide the public pseudo (display-name override), resolved avatar and slug -
        // so a participant shows their community identity and links to their profile. A user without a
        // visible community card (none/banned/suspended) falls back to the account name, no avatar/link.
        $cards = $this->directory->cards($userIds);

        // Status badges, batch-resolved (no N+1) so the participant list is coherent with the player
        // profile: Adhérent (live membership, never the stale ROLE_MEMBER), niveau, En jeu (story 30.37).
        $memberIds = array_fill_keys($this->memberships->activeMemberIds($userIds), true);
        $levels = $this->levels->levelForMany($userIds);
        $playing = $this->presence->playing($userIds);

        return array_map(function (RunParticipant $p) use ($usersById, $cards, $memberIds, $levels, $playing): array {
            $user = $usersById[$p->getUserId()] ?? null;
            $card = $cards[$p->getUserId()] ?? null;
            $level = $levels[$p->getUserId()] ?? null;

            return [
                'userId' => $p->getUserId(),
                'slug' => null !== $card ? $card['slug'] : null,
                'displayName' => (null !== $card ? $card['displayName'] : null)
                    ?? $user?->getDisplayName() ?? $user?->getEmail(),
                'avatarUrl' => null !== $card ? $card['avatarUrl'] : null,
                'joinedAt' => $p->getJoinedAt()->format(\DateTimeInterface::ATOM),
                'slotCount' => count($p->getGameSlots()),
                'isMember' => isset($memberIds[$p->getUserId()]),
                'isAdmin' => null !== $user && in_array('ROLE_ADMIN', $user->getRoles(), true),
                'level' => null !== $level ? $level['level'] : 0,
                'playing' => isset($playing[$p->getUserId()]),
            ];
        }, $participants);
    }

    /**
     * @param list<array{userId: string, slug: string|null, displayName: string|null, avatarUrl: string|null, joinedAt: string, slotCount: int, isMember: bool, isAdmin: bool, level: int, playing: bool}> $participants
     *
     * @return array<string, mixed>
     */
    private function payload(Run $run, ?string $callerId, array $participants): array
    {
        $isActive = Run::STATUS_ACTIVE === $run->getStatus();
        $isOwner = null !== $callerId && $run->isOwnedBy($callerId);

        // Story 9.42 (advisory launch warning): count slots whose solo test generation
        // failed across all participants. Never blocks anything. Draft runs only - the
        // warning is pre-launch UI, and computing it in listMine() for every historical
        // run would be a pointless findByRunId per row (review fix).
        $failedPreflightCount = 0;
        if (Run::STATUS_DRAFT === $run->getStatus()) {
            foreach ($this->participants->findByRunId($run->getId()) as $runParticipant) {
                foreach ($runParticipant->getGameSlots() as $gameSlot) {
                    if ('failed' === (($gameSlot['preflight'] ?? [])['status'] ?? null)) {
                        ++$failedPreflightCount;
                    }
                }
            }
        }

        $lastActivityAt = null;
        $pausedWithoutSave = false;
        $validationErrors = null;
        $generationLogExcerpt = null;
        $adminPassword = null;
        $sessionId = $run->getSessionId();

        if (null !== $sessionId) {
            $session = $this->sessions->findById($sessionId);
            if ($session instanceof Session) {
                if (in_array($run->getStatus(), [Run::STATUS_IDLE, Run::STATUS_RESTARTING], true)) {
                    $lastActivityAt = $session->getLastActivityAt()?->format(\DateTimeInterface::ATOM);
                    $pausedWithoutSave = $session->isPausedWithoutSave();
                }

                // Surface the reason on a reset run: validation failure (session draft) or a
                // generation/launch crash (session failed - story 17.11).
                if (Run::STATUS_DRAFT === $run->getStatus()
                    && in_array($session->getStatus(), [Session::STATUS_DRAFT, Session::STATUS_FAILED], true)) {
                    $validationErrors = $session->getValidationErrors();

                    // Owner-only bounded excerpt of the failed generation's stderr (story 9.40) -
                    // the full raw log stays admin-only via /admin/sessions/{id}/logs.
                    if ($isOwner && Session::STATUS_FAILED === $session->getStatus()) {
                        $logs = $session->getLastLogs();
                        if (null !== $logs && '' !== trim($logs)) {
                            $generationLogExcerpt = mb_substr($logs, -2000);
                        }
                    }
                }

                if ($isActive && $isOwner) {
                    $adminPassword = $session->getAdminPassword();
                }
            }
        }

        return [
            'id' => $run->getId(),
            'ownerId' => $run->getOwnerId(),
            'title' => $run->getTitle(),
            'status' => $run->getStatus(),
            'inviteToken' => $isOwner ? $run->getInviteToken() : null,
            'gameSelectionConfig' => $run->getGameSelectionConfig(),
            'connectionHost' => $isActive ? $run->getConnectionHost() : null,
            'connectionPort' => $isActive ? $run->getConnectionPort() : null,
            // Même condition de visibilité que les champs bruts : une run inactive n'expose rien.
            'connectionUri' => $isActive
                ? ArchipelagoConnectionUri::tryBuild($run->getConnectionHost(), $run->getConnectionPort())
                : null,
            'connectionPassword' => $isActive ? $run->getConnectionPassword() : null,
            'isOwner' => $isOwner,
            'participants' => $participants,
            'sessionId' => $sessionId,
            'recapPublic' => $run->isRecapPublic(),
            'lastActivityAt' => $lastActivityAt,
            'pausedWithoutSave' => $pausedWithoutSave,
            'validationErrors' => $validationErrors,
            'generationLogExcerpt' => $generationLogExcerpt,
            'failedPreflightCount' => $failedPreflightCount,
            'adminPassword' => $adminPassword,
            'createdAt' => $run->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $run->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
