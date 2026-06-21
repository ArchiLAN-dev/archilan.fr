<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application;

use App\Community\Application\CommunityUserDirectoryQueryInterface;
use App\Identity\Application\ValidationErrors;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunParticipant;
use App\PersonalRuns\Domain\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\RunRepositoryInterface;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;

final readonly class PersonalRunDrafts
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private UserRepositoryInterface $users,
        private SessionRepositoryInterface $sessions,
        private CommunityUserDirectoryQueryInterface $directory,
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

        $errs = $errors->toArray();
        if ([] !== $errs) {
            return ['run' => null, 'errors' => $errs];
        }

        $run = Run::create($ownerId, $title, new \DateTimeImmutable());
        $this->runs->save($run);

        return ['run' => $this->payload($run, $ownerId, []), 'errors' => []];
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

        $run->cancel(new \DateTimeImmutable());
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

        $run->cancel(new \DateTimeImmutable());
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

        $run->unarchive(new \DateTimeImmutable());
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
     * @return array{found: bool, authorized: bool, inviteToken: string|null, inviteUrl: string|null}
     */
    public function regenerateToken(string $runId, string $callerId): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return ['found' => false, 'authorized' => false, 'inviteToken' => null, 'inviteUrl' => null];
        }

        if (!$run->isOwnedBy($callerId)) {
            return ['found' => true, 'authorized' => false, 'inviteToken' => null, 'inviteUrl' => null];
        }

        $run->regenerateInviteToken(new \DateTimeImmutable());
        $this->runs->flush();

        return [
            'found' => true,
            'authorized' => true,
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
                $participant = RunParticipant::create($run->getId(), $callerId, new \DateTimeImmutable());
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
     * @return list<array{userId: string, slug: string|null, displayName: string|null, avatarUrl: string|null, joinedAt: string, slotCount: int}>
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

        return array_map(function (RunParticipant $p) use ($usersById, $cards): array {
            $user = $usersById[$p->getUserId()] ?? null;
            $card = $cards[$p->getUserId()] ?? null;

            return [
                'userId' => $p->getUserId(),
                'slug' => null !== $card ? $card['slug'] : null,
                'displayName' => (null !== $card ? $card['displayName'] : null)
                    ?? $user?->getDisplayName() ?? $user?->getEmail(),
                'avatarUrl' => null !== $card ? $card['avatarUrl'] : null,
                'joinedAt' => $p->getJoinedAt()->format(\DateTimeInterface::ATOM),
                'slotCount' => count($p->getGameSlots()),
            ];
        }, $participants);
    }

    /**
     * @param list<array{userId: string, displayName: string|null, joinedAt: string, slotCount: int}> $participants
     *
     * @return array<string, mixed>
     */
    private function payload(Run $run, ?string $callerId, array $participants): array
    {
        $isActive = Run::STATUS_ACTIVE === $run->getStatus();
        $isOwner = null !== $callerId && $run->isOwnedBy($callerId);

        $lastActivityAt = null;
        $pausedWithoutSave = false;
        $validationErrors = null;
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
            'connectionPassword' => $isActive ? $run->getConnectionPassword() : null,
            'isOwner' => $isOwner,
            'participants' => $participants,
            'sessionId' => $sessionId,
            'lastActivityAt' => $lastActivityAt,
            'pausedWithoutSave' => $pausedWithoutSave,
            'validationErrors' => $validationErrors,
            'adminPassword' => $adminPassword,
            'createdAt' => $run->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $run->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
