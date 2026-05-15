<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application;

use App\Identity\Application\ValidationErrors;
use App\Identity\Domain\User;
use App\PersonalRuns\Domain\PersonalRun;
use App\PersonalRuns\Domain\PersonalRunParticipant;
use App\Sessions\Domain\Session;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PersonalRunDrafts
{
    use EntityFinderTrait;

    public function __construct(
        private EntityManagerInterface $entityManager,
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

        $run = PersonalRun::create($ownerId, $title, new \DateTimeImmutable());
        $this->entityManager->persist($run);
        $this->entityManager->flush();

        return ['run' => $this->payload($run, $ownerId, []), 'errors' => []];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForOwner(string $ownerId): array
    {
        /** @var list<PersonalRun> $runs */
        $runs = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(PersonalRun::class, 'r')
            ->where('r.ownerId = :ownerId')
            ->orderBy('r.createdAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setParameter('ownerId', $ownerId)
            ->getQuery()
            ->getResult();

        return array_map(fn (PersonalRun $run): array => $this->payload($run, $ownerId, []), $runs);
    }

    /**
     * @return array{found: bool, authorized: bool, payload: array<string, mixed>|null}
     */
    public function get(string $runId, string $callerId): array
    {
        try {
            $run = $this->findOrFail(PersonalRun::class, $runId);
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
            $run = $this->findOrFail(PersonalRun::class, $runId);
        } catch (\RuntimeException) {
            return ['found' => false, 'authorized' => false, 'blocked' => false, 'blockReason' => null];
        }

        if (!$run->isOwnedBy($callerId)) {
            return ['found' => true, 'authorized' => false, 'blocked' => false, 'blockReason' => null];
        }

        if (in_array($run->getStatus(), PersonalRun::ACTIVE_STATUSES, true)) {
            return ['found' => true, 'authorized' => true, 'blocked' => true, 'blockReason' => 'run_active'];
        }

        if (!in_array($run->getStatus(), [PersonalRun::STATUS_DRAFT, PersonalRun::STATUS_IDLE], true)) {
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
            $run = $this->findOrFail(PersonalRun::class, $runId);
        } catch (\RuntimeException) {
            return ['found' => false, 'authorized' => false, 'blocked' => false, 'blockReason' => null];
        }

        if (!$run->isOwnedBy($callerId)) {
            return ['found' => true, 'authorized' => false, 'blocked' => false, 'blockReason' => null];
        }

        $archivable = [
            PersonalRun::STATUS_DRAFT,
            PersonalRun::STATUS_IDLE,
            PersonalRun::STATUS_STARTING,
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
            $run = $this->findOrFail(PersonalRun::class, $runId);
        } catch (\RuntimeException) {
            return ['found' => false, 'authorized' => false, 'blocked' => false, 'blockReason' => null];
        }

        if (!$run->isOwnedBy($callerId)) {
            return ['found' => true, 'authorized' => false, 'blocked' => false, 'blockReason' => null];
        }

        if (PersonalRun::STATUS_CANCELLED !== $run->getStatus()) {
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
            $run = $this->findOrFail(PersonalRun::class, $runId);
        } catch (\RuntimeException) {
            return ['found' => false, 'authorized' => false, 'blocked' => false, 'blockReason' => null];
        }

        if (!$run->isOwnedBy($callerId)) {
            return ['found' => true, 'authorized' => false, 'blocked' => false, 'blockReason' => null];
        }

        $nonDeletable = [PersonalRun::STATUS_ACTIVE, PersonalRun::STATUS_STOPPING];
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
            $run = $this->findOrFail(PersonalRun::class, $runId);
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
        /** @var PersonalRun|null $run */
        $run = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(PersonalRun::class, 'r')
            ->where('r.inviteToken = :token')
            ->setParameter('token', $inviteToken)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$run instanceof PersonalRun || PersonalRun::STATUS_CANCELLED === $run->getStatus()) {
            return ['status' => 'not_found', 'payload' => null];
        }

        if (!$run->isOwnedBy($callerId)) {
            $existing = $this->entityManager->find(PersonalRunParticipant::class, [
                'personalRunId' => $run->getId(),
                'userId' => $callerId,
            ]);

            if (!$existing instanceof PersonalRunParticipant) {
                $participant = PersonalRunParticipant::create($run->getId(), $callerId, new \DateTimeImmutable());
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
        /** @var PersonalRun|null $run */
        $run = $this->entityManager->createQueryBuilder()
            ->select('r')
            ->from(PersonalRun::class, 'r')
            ->where('r.inviteToken = :token')
            ->setParameter('token', $inviteToken)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$run instanceof PersonalRun || PersonalRun::STATUS_CANCELLED === $run->getStatus()) {
            return null;
        }

        $participantCount = (int) $this->entityManager->createQueryBuilder()
            ->select('COUNT(p.userId)')
            ->from(PersonalRunParticipant::class, 'p')
            ->where('p.personalRunId = :runId')
            ->setParameter('runId', $run->getId())
            ->getQuery()
            ->getSingleScalarResult();

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
        /** @var list<array{0: PersonalRunParticipant, displayName: string|null, email: string}> $rows */
        $rows = $this->entityManager->createQueryBuilder()
            ->select('p', 'u.displayName AS displayName', 'u.email AS email')
            ->from(PersonalRunParticipant::class, 'p')
            ->leftJoin(User::class, 'u', 'WITH', 'u.id = p.userId')
            ->where('p.personalRunId = :runId')
            ->orderBy('p.joinedAt', 'ASC')
            ->setParameter('runId', $runId)
            ->getQuery()
            ->getResult();

        return array_map(function (array $row): array {
            /** @var PersonalRunParticipant $p */
            $p = $row[0];
            $displayName = $row['displayName'];
            $email = $row['email'];

            return [
                'userId' => $p->getUserId(),
                'displayName' => $displayName ?? $email,
                'joinedAt' => $p->getJoinedAt()->format(\DateTimeInterface::ATOM),
                'slotCount' => count($p->getGameSlots()),
            ];
        }, $rows);
    }

    /**
     * @param list<array{userId: string, joinedAt: string}> $participants
     *
     * @return array<string, mixed>
     */
    private function payload(PersonalRun $run, ?string $callerId, array $participants): array
    {
        $isActive = PersonalRun::STATUS_ACTIVE === $run->getStatus();

        $lastActivityAt = null;
        $pausedWithoutSave = false;
        $validationErrors = null;
        $sessionId = $run->getSessionId();

        if (null !== $sessionId) {
            $session = $this->entityManager->find(Session::class, $sessionId);
            if ($session instanceof Session) {
                if (in_array($run->getStatus(), [PersonalRun::STATUS_IDLE, PersonalRun::STATUS_RESTARTING], true)) {
                    $lastActivityAt = $session->getLastActivityAt()?->format(\DateTimeInterface::ATOM);
                    $pausedWithoutSave = $session->isPausedWithoutSave();
                }

                if (PersonalRun::STATUS_DRAFT === $run->getStatus() && Session::STATUS_DRAFT === $session->getStatus()) {
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
