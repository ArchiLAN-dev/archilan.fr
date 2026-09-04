<?php

declare(strict_types=1);

namespace App\Payments\Application\Handler;

use App\Payments\Application\Message\HelloAssoOrderPaidMessage;
use App\Payments\Application\Message\SyncHelloAssoFormMessage;
use App\Payments\Application\Port\HelloAssoClientInterface;
use App\Payments\Domain\Entity\HelloAssoOrder;
use App\Payments\Domain\Entity\HelloAssoSyncLog;
use App\Payments\Domain\Repository\HelloAssoOrderRepositoryInterface;
use App\Payments\Domain\Repository\HelloAssoSyncLogRepositoryInterface;
use App\Shared\Application\Handler\LogsHandlerErrors;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * @phpstan-type HelloAssoItem array{orderId: int, status: string, amountCents: int, payerEmail: string|null, payerFirstName: string|null, payerLastName: string|null, paidAt: \DateTimeImmutable|null}
 */
#[AsMessageHandler]
final readonly class SyncHelloAssoFormHandler
{
    use LogsHandlerErrors;

    public function __construct(
        private HelloAssoClientInterface $httpClient,
        private HelloAssoOrderRepositoryInterface $orderRepository,
        private HelloAssoSyncLogRepositoryInterface $syncLogRepository,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    public function __invoke(SyncHelloAssoFormMessage $message): void
    {
        // Credentials absent means the integration is simply not configured - skip without retry.
        try {
            $this->httpClient->getConfig()->assertApiAccessConfigured();
        } catch (\RuntimeException $e) {
            $this->logger->warning('helloasso.sync_skipped_not_configured', [
                'formType' => $message->formType,
                'formSlug' => $message->formSlug,
                'reason' => $e->getMessage(),
            ]);

            return;
        }

        $now = $this->clock->now();

        try {
            $accessToken = $this->httpClient->getAccessToken();
            $items = $this->httpClient->fetchFormItems($message->formType, $message->formSlug, $accessToken);
        } catch (\Throwable $e) {
            $this->logger->error('helloasso.sync_fetch_failed', [
                'formType' => $message->formType,
                'formSlug' => $message->formSlug,
                'error' => $e->getMessage(),
            ]);
            $this->persistLog(HelloAssoSyncLog::fromFailure($message->formSlug, $e->getMessage(), $now));
            // Re-throw so Messenger can schedule a retry for transient network/API errors.
            throw $e;
        }

        $orders = $this->aggregateItemsByOrder($items);

        $pendingMessages = [];
        foreach ($orders as $order) {
            $pending = $this->upsertOrder($order, $message->formType, $message->formSlug, $now);
            if (null !== $pending) {
                $pendingMessages[] = $pending;
            }
        }

        $this->syncLogRepository->persist(HelloAssoSyncLog::fromSuccess($message->formSlug, $now));

        $this->executeWithLogging('helloasso.sync_persist_failed', fn () => $this->orderRepository->flush());

        foreach ($pendingMessages as $paidMessage) {
            $this->bus->dispatch($paidMessage);
        }

        $this->logger->info('helloasso.sync_completed', [
            'formType' => $message->formType,
            'formSlug' => $message->formSlug,
            'itemCount' => count($items),
            'orderCount' => count($orders),
        ]);
    }

    private function persistLog(HelloAssoSyncLog $log): void
    {
        try {
            $this->syncLogRepository->save($log);
        } catch (\Throwable) {
            // Log persistence must never prevent re-throwing the original error.
        }
    }

    /**
     * The HelloAsso items endpoint returns one row per ordered line: an order holding
     * several lines (membership + donation, several tickets) shows up as many items
     * sharing the same order id. Merging them before the upsert keeps one row per order -
     * persisting twice would violate the unique constraint on helloasso_order_id, and the
     * whole sync would then be rolled back and retried forever - and stores the order
     * total rather than whichever line happened to come last.
     *
     * @param list<HelloAssoItem> $items
     *
     * @return list<HelloAssoItem>
     */
    private function aggregateItemsByOrder(array $items): array
    {
        /** @var array<int, HelloAssoItem> $byOrderId */
        $byOrderId = [];

        foreach ($items as $item) {
            $previous = $byOrderId[$item['orderId']] ?? null;

            if (null === $previous) {
                $byOrderId[$item['orderId']] = $item;

                continue;
            }

            $byOrderId[$item['orderId']] = [
                'orderId' => $item['orderId'],
                'status' => '' !== $previous['status'] ? $previous['status'] : $item['status'],
                'amountCents' => $previous['amountCents'] + $item['amountCents'],
                'payerEmail' => $previous['payerEmail'] ?? $item['payerEmail'],
                'payerFirstName' => $previous['payerFirstName'] ?? $item['payerFirstName'],
                'payerLastName' => $previous['payerLastName'] ?? $item['payerLastName'],
                'paidAt' => $previous['paidAt'] ?? $item['paidAt'],
            ];
        }

        return array_values($byOrderId);
    }

    /**
     * @param HelloAssoItem $item
     */
    private function upsertOrder(array $item, string $formType, string $formSlug, \DateTimeImmutable $now): ?HelloAssoOrderPaidMessage
    {
        $found = $this->orderRepository->findByHelloAssoOrderId($item['orderId']);

        if ($found instanceof HelloAssoOrder) {
            $wasUnpaid = null === $found->getPaidAt();

            $found->updateFromSync(
                $item['status'],
                $item['amountCents'],
                $item['payerEmail'],
                $item['payerFirstName'],
                $item['payerLastName'],
                $item['paidAt'],
                $now,
            );

            if ($wasUnpaid && null !== $item['paidAt']) {
                return new HelloAssoOrderPaidMessage(
                    (string) $item['orderId'],
                    $formSlug,
                    $item['payerEmail'],
                    $item['paidAt'],
                );
            }

            return null;
        }

        $order = HelloAssoOrder::fromHelloAsso(
            $item['orderId'],
            $formType,
            $formSlug,
            $item['status'],
            $item['amountCents'],
            $item['payerEmail'],
            $item['payerFirstName'],
            $item['payerLastName'],
            $item['paidAt'],
            $now,
        );

        $this->orderRepository->persist($order);

        if (null !== $item['paidAt']) {
            return new HelloAssoOrderPaidMessage(
                (string) $item['orderId'],
                $formSlug,
                $item['payerEmail'],
                $item['paidAt'],
            );
        }

        return null;
    }
}
