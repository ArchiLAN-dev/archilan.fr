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

        $results = $this->entityManager->getRepository(HelloAssoOrder::class)->findBy(
            ['formSlug' => $formSlug, 'payerEmail' => $payerEmail],
            ['syncedAt' => 'DESC'],
            1,
        );
        /** @var HelloAssoOrder|null $order */
        $order = $results[0] ?? null;

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
