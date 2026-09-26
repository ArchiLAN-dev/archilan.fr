<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payments;

use App\Payments\Application\Command\HandleHelloAssoWebhook;
use App\Payments\Application\Message\HelloAssoOrderPaidMessage;
use App\Payments\Application\Message\SyncHelloAssoFormMessage;
use App\Payments\Application\Port\HelloAssoClientInterface;
use App\Payments\Application\Support\HelloAssoConfig;
use App\Tests\Unit\CatalogSync\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Story 22.7: the webhook is the fast path to a membership, and it used to give up in silence. When the
 * order could not be verified it returned before triggering the form sync - answering 200, so HelloAsso
 * never retried - and nothing caught the payment until an admin clicked "Synchroniser HelloAsso".
 */
final class HandleHelloAssoWebhookTest extends TestCase
{
    private const string MEMBERSHIP_SLUG = 'adhesion-2026';

    private RecordingMessageBus $bus;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->bus = new RecordingMessageBus();
        $this->logger = new RecordingLogger();
    }

    public function testAnOrderThatCannotBeVerifiedStillTriggersTheFormSync(): void
    {
        $client = self::createStub(HelloAssoClientInterface::class);
        $client->method('getAccessToken')->willThrowException(new \RuntimeException('401 Unauthorized'));

        $this->webhook($client)->handle(42, HelloAssoConfig::FORM_TYPE_MEMBERSHIP, self::MEMBERSHIP_SLUG, 'payer@example.org', new \DateTimeImmutable('2026-09-27'));

        self::assertSame([], $this->bus->messagesOf(HelloAssoOrderPaidMessage::class), 'an unverified order is not reported paid');
        $syncs = $this->bus->messagesOf(SyncHelloAssoFormMessage::class);
        self::assertCount(1, $syncs, 'the sync, retried by Messenger, is what catches the payment');
        self::assertSame(self::MEMBERSHIP_SLUG, $syncs[0]->formSlug);
    }

    public function testAnOrderHelloAssoDoesNotReturnStillTriggersTheFormSync(): void
    {
        $client = self::createStub(HelloAssoClientInterface::class);
        $client->method('getAccessToken')->willReturn('token');
        $client->method('fetchOrder')->willReturn(null);

        $this->webhook($client)->handle(42, HelloAssoConfig::FORM_TYPE_MEMBERSHIP, self::MEMBERSHIP_SLUG, 'payer@example.org', new \DateTimeImmutable('2026-09-27'));

        self::assertSame([], $this->bus->messagesOf(HelloAssoOrderPaidMessage::class));
        self::assertCount(1, $this->bus->messagesOf(SyncHelloAssoFormMessage::class));
    }

    public function testAMembershipOrderOnAnotherFormIsReported(): void
    {
        // A renamed form or a new season's form: the membership handler ignores it by slug, and nothing
        // used to say so.
        $client = self::createStub(HelloAssoClientInterface::class);
        $client->method('getAccessToken')->willReturn('token');
        $client->method('fetchOrder')->willReturn(['orderId' => 42, 'amountCents' => 2500, 'payerEmail' => 'payer@example.org', 'payerFirstName' => null, 'payerLastName' => null, 'paidAt' => null]);

        $this->webhook($client)->handle(42, HelloAssoConfig::FORM_TYPE_MEMBERSHIP, 'adhesion-2027', 'payer@example.org', new \DateTimeImmutable('2026-09-27'));

        self::assertContains(['level' => 'warning', 'message' => 'helloasso.webhook.membership_form_mismatch'], $this->logger->logs);
    }

    public function testAnOrderOfAnotherKindOfFormIsNotReported(): void
    {
        $client = self::createStub(HelloAssoClientInterface::class);
        $client->method('getAccessToken')->willReturn('token');
        $client->method('fetchOrder')->willReturn(['orderId' => 42, 'amountCents' => 2500, 'payerEmail' => 'payer@example.org', 'payerFirstName' => null, 'payerLastName' => null, 'paidAt' => null]);

        $this->webhook($client)->handle(42, HelloAssoConfig::FORM_TYPE_SHOP, 'boutique', 'payer@example.org', new \DateTimeImmutable('2026-09-27'));

        self::assertNotContains(['level' => 'warning', 'message' => 'helloasso.webhook.membership_form_mismatch'], $this->logger->logs);
    }

    private function webhook(HelloAssoClientInterface $client): HandleHelloAssoWebhook
    {
        return new HandleHelloAssoWebhook($client, $this->bus, $this->logger, self::MEMBERSHIP_SLUG);
    }
}
