<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\GameSelection\Domain\ArchipelagoGame;
use App\Registrations\Domain\Registration;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PlayerSessionConnection
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * Returns the session connection details for a confirmed registrant, or null if access is denied.
     *
     * Access is granted only when:
     *   - the registration exists and belongs to the given user
     *   - the registration is confirmed (reserved + submittedAt set)
     *
     * @return array{session: array<string, mixed>|null, slots: list<array{slotName: string, slotOrder: int, gameId: string, gameName: string}>}|null
     */
    public function getConnection(string $registrationId, string $userId): ?array
    {
        $registration = $this->entityManager->find(Registration::class, $registrationId);

        if (!$registration instanceof Registration
            || $registration->getUserId() !== $userId
            || !$registration->isReserved()
            || null === $registration->getSubmittedAt()
        ) {
            return null;
        }

        /** @var list<string> $sessionIds */
        $sessionIds = $this->entityManager->createQueryBuilder()
            ->select('DISTINCT ss.sessionId')
            ->from(SessionSlot::class, 'ss')
            ->where('ss.registrationId = :registrationId')
            ->setParameter('registrationId', $registrationId)
            ->getQuery()
            ->getSingleColumnResult();

        if ([] === $sessionIds) {
            return ['session' => null, 'slots' => []];
        }

        /** @var Session|null $session */
        $session = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Session::class, 's')
            ->where('s.id IN (:ids)')
            ->setParameter('ids', $sessionIds)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$session instanceof Session) {
            return ['session' => null, 'slots' => []];
        }

        /** @var list<SessionSlot> $sessionSlots */
        $sessionSlots = $this->entityManager
            ->getRepository(SessionSlot::class)
            ->findBy(['registrationId' => $registrationId, 'sessionId' => $session->getId()], ['slotOrder' => 'ASC']);

        $gameIds = array_values(array_unique(
            array_map(static fn (SessionSlot $s) => $s->getGameId(), $sessionSlots),
        ));

        /** @var list<ArchipelagoGame> $games */
        $games = $this->entityManager->createQueryBuilder()
            ->select('g')
            ->from(ArchipelagoGame::class, 'g')
            ->where('g.id IN (:ids)')
            ->setParameter('ids', $gameIds)
            ->getQuery()
            ->getResult();

        /** @var array<string, string> $nameById */
        $nameById = [];
        foreach ($games as $game) {
            $nameById[$game->getId()] = $game->getName();
        }

        $slots = array_map(
            static fn (SessionSlot $s): array => [
                'slotName' => $s->getSlotName(),
                'slotOrder' => $s->getSlotOrder(),
                'gameId' => $s->getGameId(),
                'gameName' => $nameById[$s->getGameId()] ?? $s->getGameId(),
            ],
            $sessionSlots,
        );

        return [
            'session' => $session->payload(),
            'slots' => $slots,
        ];
    }
}
