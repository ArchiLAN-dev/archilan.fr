<?php

declare(strict_types=1);

namespace App\Payments\Application\Command;

use App\Payments\Application\Message\HelloAssoOrderPaidMessage;
use App\Payments\Application\Message\SyncHelloAssoFormMessage;
use App\Payments\Application\Port\HelloAssoClientInterface;
use App\Payments\Application\Support\HelloAssoConfig;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class HandleHelloAssoWebhook
{
    public function __construct(
        private HelloAssoClientInterface $httpClient,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
        #[Autowire('%env(HELLOASSO_MEMBERSHIP_FORM_SLUG)%')]
        private string $membershipFormSlug,
    ) {
    }

    /**
     * The webhook is not signed, so its body proves nothing: anyone can post one. Only the order read
     * back from HelloAsso with our own token decides who paid, on which form and when.
     */
    public function handle(int $orderId, string $formType, string $formSlug): void
    {
        $this->logger->info('helloasso.webhook.verifying', [
            'orderId' => $orderId,
            'formType' => $formType,
            'formSlug' => $formSlug,
        ]);

        $order = $this->verifiedOrder($orderId);
        if (null !== $order) {
            $formSlug = $order['formSlug'] ?? $formSlug;
            $formType = null !== $order['formType'] ? HelloAssoConfig::fromApiFormType($order['formType']) : $formType;
        }

        // A membership order on any other form than the configured one is ignored by the membership
        // handler, by slug. A renamed form or a new season's form then stops every membership, and
        // nothing used to say so (story 22.7).
        if (HelloAssoConfig::FORM_TYPE_MEMBERSHIP === $formType && '' !== $this->membershipFormSlug && $formSlug !== $this->membershipFormSlug) {
            $this->logger->warning('helloasso.webhook.membership_form_mismatch', [
                'orderId' => $orderId,
                'formSlug' => $formSlug,
                'configuredFormSlug' => $this->membershipFormSlug,
            ]);
        }

        if (null !== $order && $order['isPaid'] && null !== $order['formSlug'] && null !== $order['paidAt']) {
            $this->bus->dispatch(new HelloAssoOrderPaidMessage(
                (string) $orderId,
                $order['formSlug'],
                $order['payerEmail'],
                $order['paidAt'],
            ));

            $this->logger->info('helloasso.webhook.paid_message_dispatched', [
                'orderId' => $orderId,
            ]);
        }

        // Always trigger a full form sync, even when the order could not be verified: the sync is async,
        // retried by Messenger, and reports the order paid on its own. Returning before it (story 22.7)
        // left an unverified payment to an admin click, since the webhook still answered 200 and
        // HelloAsso never retried.
        $this->bus->dispatch(new SyncHelloAssoFormMessage($formType, $formSlug));

        $this->logger->info('helloasso.webhook.sync_triggered', [
            'orderId' => $orderId,
            'formType' => $formType,
            'formSlug' => $formSlug,
        ]);
    }

    /**
     * @return array{orderId: int, amountCents: int, payerEmail: string|null, payerFirstName: string|null, payerLastName: string|null, paidAt: \DateTimeImmutable|null, formSlug: string|null, formType: string|null, isPaid: bool}|null
     */
    private function verifiedOrder(int $orderId): ?array
    {
        try {
            $accessToken = $this->httpClient->getAccessToken();
            $order = $this->httpClient->fetchOrder($orderId, $accessToken);
        } catch (\Throwable $e) {
            $this->logger->error('helloasso.webhook.verify_failed', [
                'orderId' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }

        if (null === $order) {
            $this->logger->warning('helloasso.webhook.order_not_found', [
                'orderId' => $orderId,
            ]);

            return null;
        }

        $this->logger->info('helloasso.webhook.order_verified', [
            'orderId' => $orderId,
            'amountCents' => $order['amountCents'],
        ]);

        return $order;
    }
}
