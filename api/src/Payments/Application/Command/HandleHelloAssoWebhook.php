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

        if ($this->verify($orderId) && null !== $paidAt) {
            // Dispatch the paid message immediately using webhook-provided data.
            // The items endpoint does not reliably expose payer email on individual items,
            // but the Order webhook payload does - use it here to avoid the sync round-trip for email.
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
     * The webhook is not signed: re-reading the order from HelloAsso with our own token is what proves it.
     */
    private function verify(int $orderId): bool
    {
        try {
            $accessToken = $this->httpClient->getAccessToken();
            $order = $this->httpClient->fetchOrder($orderId, $accessToken);
        } catch (\Throwable $e) {
            $this->logger->error('helloasso.webhook.verify_failed', [
                'orderId' => $orderId,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if (null === $order) {
            $this->logger->warning('helloasso.webhook.order_not_found', [
                'orderId' => $orderId,
            ]);

            return false;
        }

        $this->logger->info('helloasso.webhook.order_verified', [
            'orderId' => $orderId,
            'amountCents' => $order['amountCents'],
        ]);

        return true;
    }
}
