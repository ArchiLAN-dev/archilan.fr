<?php

declare(strict_types=1);

namespace App\Payments\Application;

use App\Events\Domain\Event;
use App\Payments\Domain\HelloAssoOrder;
use App\Shared\Application\EntityFinderTrait;
use Doctrine\ORM\EntityManagerInterface;

final readonly class HelloAssoPaymentLookup
{
    use EntityFinderTrait;

    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{status: string, amountCents: int, syncedAt: string, isStale: bool}|null
     */
    public function findForEventAndEmail(string $eventId, string $payerEmail): ?array
    {
        try {
            $event = $this->findOrFail(Event::class, $eventId);
        } catch (\RuntimeException) {
            return null;
        }

        $formSlug = $event->getHelloassoFormSlug();

        if (null === $formSlug) {
            return null;
        }

        /** @var HelloAssoOrder|null $order */
        $order = $this->entityManager->createQueryBuilder()
            ->select('o')
            ->from(HelloAssoOrder::class, 'o')
            ->where('o.formSlug = :formSlug')
            ->andWhere('o.payerEmail = :payerEmail')
            ->setParameter('formSlug', $formSlug)
            ->setParameter('payerEmail', $payerEmail)
            ->orderBy('o.syncedAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if (!$order instanceof HelloAssoOrder) {
            return null;
        }

        $staleThreshold = new \DateTimeImmutable('-24 hours');

        return [
            'status' => $order->getStatus(),
            'amountCents' => $order->getAmountCents(),
            'syncedAt' => $order->getSyncedAt()->format(\DateTimeInterface::ATOM),
            'isStale' => $order->getSyncedAt() < $staleThreshold,
        ];
    }
}
