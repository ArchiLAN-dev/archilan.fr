<?php

declare(strict_types=1);

namespace App\Payments\Application;

use App\Events\Domain\Event;
use App\Payments\Domain\HelloAssoSyncLog;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\ORM\EntityManagerInterface;

final readonly class AdminHelloAssoSyncStatus
{
    use EntityFinderTrait;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * Returns recent sync attempts for an event's HelloAsso form, or null if the event is not found.
     *
     * @return array{formSlug: string|null, recentSyncs: list<array{attemptAt: string, success: bool, errorMessage: string|null}>}|null
     */
    public function getForEvent(string $eventId): ?array
    {
        try {
            $event = $this->findOrFail(Event::class, $eventId);
        } catch (\RuntimeException) {
            return null;
        }

        $formSlug = $event->getHelloassoFormSlug();

        if (null === $formSlug) {
            return ['formSlug' => null, 'recentSyncs' => []];
        }

        /** @var list<HelloAssoSyncLog> $logs */
        $logs = $this->entityManager->createQueryBuilder()
            ->select('l')
            ->from(HelloAssoSyncLog::class, 'l')
            ->where('l.formSlug = :formSlug')
            ->setParameter('formSlug', $formSlug)
            ->orderBy('l.attemptAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        $recentSyncs = array_map(
            static fn (HelloAssoSyncLog $log): array => [
                'attemptAt' => $log->getAttemptAt()->format(\DateTimeInterface::ATOM),
                'success' => $log->isSuccess(),
                'errorMessage' => $log->getErrorMessage(),
            ],
            $logs,
        );

        return ['formSlug' => $formSlug, 'recentSyncs' => $recentSyncs];
    }
}
