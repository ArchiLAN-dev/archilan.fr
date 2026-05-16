<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\GameSelection\Domain\Game;
use App\Registrations\Domain\Registration;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;

final readonly class PlayerSessionConnection
{
    use EntityFinderTrait;

    private string $sessionSlotTable;
    private string $sessionTable;

    public function __construct(
        private EntityManagerInterface $entityManager,
        private Connection $connection,
    ) {
        $this->sessionSlotTable = $entityManager->getClassMetadata(SessionSlot::class)->getTableName();
        $this->sessionTable = $entityManager->getClassMetadata(Session::class)->getTableName();
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
        try {
            $registration = $this->findOrFail(Registration::class, $registrationId);
        } catch (\RuntimeException) {
            return null;
        }

        if ($registration->getUserId() !== $userId
            || !$registration->isReserved()
            || null === $registration->getSubmittedAt()
        ) {
            return null;
        }

        $qb = $this->connection->createQueryBuilder();
        $rows = $qb->select('DISTINCT ss.session_id')
            ->from($this->sessionSlotTable, 'ss')
            ->where($qb->expr()->eq('ss.registration_id', ':registrationId'))
            ->setParameter('registrationId', $registrationId)
            ->executeQuery()
            ->fetchFirstColumn();

        /** @var list<string> $sessionIds */
        $sessionIds = array_values(array_filter($rows, 'is_string'));

        if ([] === $sessionIds) {
            return ['session' => null, 'slots' => []];
        }

        $qb2 = $this->connection->createQueryBuilder();
        $placeholders = array_map(fn (string $id) => $qb2->createNamedParameter($id), $sessionIds);
        $sessionIdResult = $qb2->select('s.id')
            ->from($this->sessionTable, 's')
            ->where($qb2->expr()->in('s.id', $placeholders))
            ->orderBy('s.created_at', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();

        if (false === $sessionIdResult || !is_string($sessionIdResult)) {
            return ['session' => null, 'slots' => []];
        }

        /** @var Session|null $session */
        $session = $this->entityManager->find(Session::class, $sessionIdResult);

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

        /** @var list<Game> $games */
        $games = $this->entityManager->getRepository(Game::class)->findBy(['id' => $gameIds]);

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
