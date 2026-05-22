<?php

declare(strict_types=1);

namespace App\Payments\Application;

use App\Payments\Application\Message\HelloAssoOrderPaidMessage;
use App\Payments\Domain\HelloAssoOrder;
use App\Payments\Domain\HelloAssoOrderRepositoryInterface;
use App\Payments\Domain\HelloAssoSyncLog;
use App\Payments\Domain\HelloAssoSyncLogRepositoryInterface;
use App\Payments\Infrastructure\HelloAssoHttpClient;
use App\Shared\Application\Handler\LogsHandlerErrors;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;

#[AsMessageHandler]
final readonly class SyncHelloAssoFormHandler
{
    use LogsHandlerErrors;

    public function __construct(
        private HelloAssoHttpClient $httpClient,
        private HelloAssoOrderRepositoryInterface $orderRepository,
        private HelloAssoSyncLogRepositoryInterface $syncLogRepository,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
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

        $now = new \DateTimeImmutable();

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

        $pendingMessages = [];
        foreach ($items as $item) {
            $pending = $this->upsertOrder($item, $message->formType, $message->formSlug, $now);
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
     * @param array{orderId: int, status: string, amountCents: int, payerEmail: string|null, payerFirstName: string|null, payerLastName: string|null, paidAt: \DateTimeImmutable|null} $item
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
