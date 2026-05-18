<?php

declare(strict_types=1);

namespace App\Payments\Application;

use App\Payments\Application\Message\HelloAssoOrderPaidMessage;
use App\Payments\Infrastructure\HelloAssoHttpClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class HandleHelloAssoWebhook
{
    public function __construct(
        private HelloAssoHttpClient $httpClient,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function handle(
        int $orderId,
        string $formType,
        string $formSlug,
        ?string $payerEmail = null,
        ?\DateTimeImmutable $paidAt = null,
    ): void {
        $this->logger->info('helloasso.webhook.verifying', [
            'orderId' => $orderId,
            'formType' => $formType,
            'formSlug' => $formSlug,
        ]);

        try {
            $accessToken = $this->httpClient->getAccessToken();
            $order = $this->httpClient->fetchOrder($orderId, $accessToken);
        } catch (\Throwable $e) {
            $this->logger->error('helloasso.webhook.verify_failed', [
                'orderId' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return;
        }

        if (null === $order) {
            $this->logger->warning('helloasso.webhook.order_not_found', [
                'orderId' => $orderId,
            ]);

            return;
        }

        $this->logger->info('helloasso.webhook.order_verified', [
            'orderId' => $orderId,
            'payerEmail' => $payerEmail,
            'amountCents' => $order['amountCents'],
        ]);

        // Dispatch the paid message immediately using webhook-provided data.
        // The items endpoint does not reliably expose payer email on individual items,
        // but the Order webhook payload does - use it here to avoid the sync round-trip for email.
        if (null !== $paidAt) {
            $this->bus->dispatch(new HelloAssoOrderPaidMessage(
                (string) $orderId,
                $formSlug,
                $payerEmail,
                $paidAt,
            ));

            $this->logger->info('helloasso.webhook.paid_message_dispatched', [
                'orderId' => $orderId,
            ]);
        }

        // Also trigger a full form sync to persist/update the HelloAssoOrder record.
        $this->bus->dispatch(new SyncHelloAssoFormMessage($formType, $formSlug));

        $this->logger->info('helloasso.webhook.sync_triggered', [
            'orderId' => $orderId,
            'formType' => $formType,
            'formSlug' => $formSlug,
        ]);
    }
}
