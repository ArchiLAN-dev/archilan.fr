<?php

declare(strict_types=1);

namespace App\Payments\Application;

use App\Events\Domain\EventRepositoryInterface;
use App\Payments\Domain\HelloAssoOrder;
use App\Payments\Domain\HelloAssoOrderRepositoryInterface;

final readonly class HelloAssoPaymentLookup
{
    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private HelloAssoOrderRepositoryInterface $orderRepository,
    ) {
    }

    /**
     * @return array{status: string, amountCents: int, syncedAt: string, isStale: bool}|null
     */
    public function findForEventAndEmail(string $eventId, string $payerEmail): ?array
    {
        $event = $this->eventRepository->findById($eventId);
        if (null === $event) {
            return null;
        }

        $formSlug = $event->getHelloassoFormSlug();

        if (null === $formSlug) {
            return null;
        }

        $results = $this->orderRepository->findByFormSlugAndPayerEmail($formSlug, $payerEmail, 1);
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
