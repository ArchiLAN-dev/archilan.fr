<?php

declare(strict_types=1);

namespace App\Payments\Application;

use App\Payments\Domain\HelloAssoOrder;
use App\Payments\Domain\HelloAssoSyncLog;
use App\Payments\Infrastructure\HelloAssoHttpClient;
use App\Shared\Application\Handler\LogsHandlerErrors;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class SyncHelloAssoFormHandler
{
    use LogsHandlerErrors;

    public function __construct(
        private HelloAssoHttpClient $httpClient,
        private EntityManagerInterface $entityManager,
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

        foreach ($items as $item) {
            $this->upsertOrder($item, $message->formType, $message->formSlug, $now);
        }

        $this->entityManager->persist(HelloAssoSyncLog::fromSuccess($message->formSlug, $now));

        $this->executeWithLogging('helloasso.sync_persist_failed', fn () => $this->entityManager->flush());

        $this->logger->info('helloasso.sync_completed', [
            'formType' => $message->formType,
            'formSlug' => $message->formSlug,
            'itemCount' => count($items),
        ]);
    }

    private function persistLog(HelloAssoSyncLog $log): void
    {
        try {
            $this->entityManager->persist($log);
            $this->entityManager->flush();
        } catch (\Throwable) {
            // Log persistence must never prevent re-throwing the original error.
        }
    }

    /**
     * @param array{orderId: int, status: string, amountCents: int, payerEmail: string|null, payerFirstName: string|null, payerLastName: string|null, paidAt: \DateTimeImmutable|null} $item
     */
    private function upsertOrder(array $item, string $formType, string $formSlug, \DateTimeImmutable $now): void
    {
        $found = $this->entityManager->getRepository(HelloAssoOrder::class)
            ->findOneBy(['helloassoOrderId' => $item['orderId']]);

        if ($found instanceof HelloAssoOrder) {
            $found->updateFromSync(
                $item['status'],
                $item['amountCents'],
                $item['payerEmail'],
                $item['payerFirstName'],
                $item['payerLastName'],
                $item['paidAt'],
                $now,
            );

            return;
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

        $this->entityManager->persist($order);
    }
}
