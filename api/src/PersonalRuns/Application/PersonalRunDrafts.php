<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application;

use App\Identity\Application\ValidationErrors;
use App\Identity\Domain\User;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunParticipant;
use App\Sessions\Domain\Session;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PersonalRunDrafts
{
    use EntityFinderTrait;

    private string $runParticipantTable;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
        private string $siteUrl,
    ) {
        $this->runParticipantTable = $entityManager->getClassMetadata(RunParticipant::class)->getTableName();
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
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return ['run' => $this->payload($run, $ownerId, []), 'errors' => []];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForOwner(string $ownerId): array
    {
        /** @var list<Run> $runs */
        $runs = $this->entityManager->getRepository(Run::class)->findBy(
            ['ownerId' => $ownerId],
            ['createdAt' => 'DESC', 'id' => 'DESC'],
        );

        return array_map(fn (Run $run): array => $this->payload($run, $ownerId, []), $runs);
    }

    /**
     * @return array{found: bool, authorized: bool, payload: array<string, mixed>|null}
     */
    public function get(string $runId, string $callerId): array
    {
        try {
            $run = $this->findOrFail(Run::class, $runId);
        } catch (\RuntimeException) {
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
        try {
            $run = $this->findOrFail(Run::class, $runId);
        } catch (\RuntimeException) {
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
        $this->entityManager->flush();

        return ['found' => true, 'authorized' => true, 'blocked' => false, 'blockReason' => null];
    }

    /**
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null}
     */
    public function archive(string $runId, string $callerId): array
    {
        try {
            $run = $this->findOrFail(Run::class, $runId);
        } catch (\RuntimeException) {
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
        $this->entityManager->flush();

        return ['found' => true, 'authorized' => true, 'blocked' => false, 'blockReason' => null];
    }

    /**
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null}
     */
    public function unarchive(string $runId, string $callerId): array
    {
        try {
            $run = $this->findOrFail(Run::class, $runId);
        } catch (\RuntimeException) {
            return ['found' => false, 'authorized' => false, 'blocked' => false, 'blockReason' => null];
        }

        if (!$run->isOwnedBy($callerId)) {
            return ['found' => true, 'authorized' => false, 'blocked' => false, 'blockReason' => null];
        }

        if (Run::STATUS_CANCELLED !== $run->getStatus()) {
            return ['found' => true, 'authorized' => true, 'blocked' => true, 'blockReason' => 'run_not_archived'];
        }

        $run->unarchive(new \DateTimeImmutable());
        $this->entityManager->flush();

        return ['found' => true, 'authorized' => true, 'blocked' => false, 'blockReason' => null];
    }

    /**
     * @return array{found: bool, authorized: bool, blocked: bool, blockReason: string|null}
     */
    public function hardDelete(string $runId, string $callerId): array
    {
        try {
            $run = $this->findOrFail(Run::class, $runId);
        } catch (\RuntimeException) {
            return ['found' => false, 'authorized' => false, 'blocked' => false, 'blockReason' => null];
        }

        if (!$run->isOwnedBy($callerId)) {
            return ['found' => true, 'authorized' => false, 'blocked' => false, 'blockReason' => null];
        }

        $nonDeletable = [Run::STATUS_ACTIVE, Run::STATUS_STOPPING];
        if (in_array($run->getStatus(), $nonDeletable, true)) {
            return ['found' => true, 'authorized' => true, 'blocked' => true, 'blockReason' => 'run_active'];
        }

        $this->entityManager->remove($run);
        $this->entityManager->flush();

        return ['found' => true, 'authorized' => true, 'blocked' => false, 'blockReason' => null];
    }

    /**
     * @return array{found: bool, authorized: bool, inviteToken: string|null, inviteUrl: string|null}
     */
    public function regenerateToken(string $runId, string $callerId): array
    {
        try {
            $run = $this->findOrFail(Run::class, $runId);
        } catch (\RuntimeException) {
            return ['found' => false, 'authorized' => false, 'inviteToken' => null, 'inviteUrl' => null];
        }

        if (!$run->isOwnedBy($callerId)) {
            return ['found' => true, 'authorized' => false, 'inviteToken' => null, 'inviteUrl' => null];
        }

        $run->regenerateInviteToken(new \DateTimeImmutable());
        $this->entityManager->flush();

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
        /** @var Run|null $run */
        $run = $this->entityManager->getRepository(Run::class)->findOneBy(['inviteToken' => $inviteToken]);

        if (!$run instanceof Run || Run::STATUS_CANCELLED === $run->getStatus()) {
            return ['status' => 'not_found', 'payload' => null];
        }

        if (!$run->isOwnedBy($callerId)) {
            $existing = $this->entityManager->find(RunParticipant::class, [
                'runId' => $run->getId(),
                'userId' => $callerId,
            ]);

            if (!$existing instanceof RunParticipant) {
                $participant = RunParticipant::create($run->getId(), $callerId, new \DateTimeImmutable());
                $this->entityManager->persist($participant);
                $this->entityManager->flush();
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
        /** @var Run|null $run */
        $run = $this->entityManager->getRepository(Run::class)->findOneBy(['inviteToken' => $inviteToken]);

        if (!$run instanceof Run || Run::STATUS_CANCELLED === $run->getStatus()) {
            return null;
        }

        $qb = $this->connection->createQueryBuilder();
        $countRaw = $qb->select('COUNT(p.user_id)')
            ->from($this->runParticipantTable, 'p')
            ->where($qb->expr()->eq('p.personal_run_id', ':runId'))
            ->setParameter('runId', $run->getId())
            ->executeQuery()
            ->fetchOne();

        $participantCount = (false !== $countRaw && is_numeric($countRaw)) ? (int) $countRaw : 0;

        $owner = $this->entityManager->find(User::class, $run->getOwnerId());
        $ownerName = $owner instanceof User ? $owner->getDisplayName() : null;

        return [
            'title' => $run->getTitle(),
            'ownerName' => $ownerName,
            'participantCount' => $participantCount,
            'status' => $run->getStatus(),
        ];
    }

    /**
     * @return list<array{userId: string, joinedAt: string, slotCount: int}>
     */
    private function getParticipants(string $runId): array
    {
        /** @var list<RunParticipant> $participants */
        $participants = $this->entityManager->getRepository(RunParticipant::class)->findBy(
            ['runId' => $runId],
            ['joinedAt' => 'ASC'],
        );

        if ([] === $participants) {
            return [];
        }

        $userIds = array_unique(array_map(static fn (RunParticipant $p) => $p->getUserId(), $participants));

        /** @var list<User> $users */
        $users = $this->entityManager->getRepository(User::class)->findBy(['id' => $userIds]);

        /** @var array<string, User> $usersById */
        $usersById = [];
        foreach ($users as $user) {
            $usersById[$user->getId()] = $user;
        }

        return array_map(function (RunParticipant $p) use ($usersById): array {
            $user = $usersById[$p->getUserId()] ?? null;

            return [
                'userId' => $p->getUserId(),
                'displayName' => $user?->getDisplayName() ?? $user?->getEmail(),
                'joinedAt' => $p->getJoinedAt()->format(\DateTimeInterface::ATOM),
                'slotCount' => count($p->getGameSlots()),
            ];
        }, $participants);
    }

    /**
     * @param list<array{userId: string, joinedAt: string}> $participants
     *
     * @return array<string, mixed>
     */
    private function payload(Run $run, ?string $callerId, array $participants): array
    {
        $isActive = Run::STATUS_ACTIVE === $run->getStatus();

        $lastActivityAt = null;
        $pausedWithoutSave = false;
        $validationErrors = null;
        $sessionId = $run->getSessionId();

        if (null !== $sessionId) {
            $session = $this->entityManager->find(Session::class, $sessionId);
            if ($session instanceof Session) {
                if (in_array($run->getStatus(), [Run::STATUS_IDLE, Run::STATUS_RESTARTING], true)) {
                    $lastActivityAt = $session->getLastActivityAt()?->format(\DateTimeInterface::ATOM);
                    $pausedWithoutSave = $session->isPausedWithoutSave();
                }

                if (Run::STATUS_DRAFT === $run->getStatus() && Session::STATUS_DRAFT === $session->getStatus()) {
                    $validationErrors = $session->getValidationErrors();
                }
            }
        }

        return [
            'id' => $run->getId(),
            'ownerId' => $run->getOwnerId(),
            'title' => $run->getTitle(),
            'status' => $run->getStatus(),
            'inviteToken' => $run->getInviteToken(),
            'gameSelectionConfig' => $run->getGameSelectionConfig(),
            'connectionHost' => $isActive ? $run->getConnectionHost() : null,
            'connectionPort' => $isActive ? $run->getConnectionPort() : null,
            'connectionPassword' => $isActive ? $run->getConnectionPassword() : null,
            'isOwner' => null !== $callerId && $run->isOwnedBy($callerId),
            'participants' => $participants,
            'sessionId' => $sessionId,
            'lastActivityAt' => $lastActivityAt,
            'pausedWithoutSave' => $pausedWithoutSave,
            'validationErrors' => $validationErrors,
            'createdAt' => $run->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $run->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
