<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Payments\Application\HelloAssoConfig;
use App\Payments\Application\Message\HelloAssoOrderPaidMessage;
use App\Payments\Application\SyncHelloAssoFormHandler;
use App\Payments\Application\SyncHelloAssoFormMessage;
use App\Payments\Domain\HelloAssoOrder;
use App\Payments\Domain\HelloAssoSyncLog;
use App\Payments\Infrastructure\DoctrineHelloAssoOrderRepository;
use App\Payments\Infrastructure\DoctrineHelloAssoSyncLogRepository;
use App\Payments\Infrastructure\HelloAssoHttpClient;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class HelloAssoSyncHandlerTest extends FunctionalTestCase
{
    public function testSyncFetchesHelloAssoItemsAndPersistsInternalOrders(): void
    {
        $handler = $this->handler([
            new MockResponse('{"access_token":"server-token"}'),
            new MockResponse(json_encode([
                'data' => [[
                    'order' => ['id' => 123456, 'date' => '2026-05-02T10:15:00+00:00'],
                    'state' => 'Processed',
                    'amount' => 2500,
                    'payer' => [
                        'email' => 'payer@example.org',
                        'firstName' => 'Ada',
                        'lastName' => 'Lovelace',
                    ],
                    'user' => ['firstName' => 'Ada', 'lastName' => 'Lovelace'],
                ]],
                'pagination' => ['totalCount' => 1],
            ], JSON_THROW_ON_ERROR)),
        ]);

        $handler(new SyncHelloAssoFormMessage(HelloAssoConfig::FORM_TYPE_EVENT, 'archilan-spring-2027'));

        $orders = $this->entityManager->getRepository(HelloAssoOrder::class)->findAll();
        self::assertCount(1, $orders);
        self::assertInstanceOf(HelloAssoOrder::class, $orders[0]);
        self::assertSame(123456, $orders[0]->getHelloassoOrderId());
        self::assertSame(HelloAssoConfig::FORM_TYPE_EVENT, $orders[0]->getFormType());
        self::assertSame('archilan-spring-2027', $orders[0]->getFormSlug());
        self::assertSame('Processed', $orders[0]->getStatus());
        self::assertSame(2500, $orders[0]->getAmountCents());
        self::assertSame('payer@example.org', $orders[0]->getPayerEmail());

        $logs = $this->entityManager->getRepository(HelloAssoSyncLog::class)->findAll();
        self::assertCount(1, $logs);
        self::assertInstanceOf(HelloAssoSyncLog::class, $logs[0]);
        self::assertTrue($logs[0]->isSuccess());
    }

    public function testSyncDispatchesPaidMessageOnceForNewPaidOrder(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::callback(static fn (object $message): bool => $message instanceof HelloAssoOrderPaidMessage
                && '123456' === $message->helloassoOrderId
                && 'payer@example.org' === $message->payerEmail
                && $message->paidAt instanceof \DateTimeImmutable))
            ->willReturn(new Envelope(new \stdClass()));
        $handler = $this->handler([
            new MockResponse('{"access_token":"server-token"}'),
            $this->itemsResponse(123456, 'payer@example.org', '2026-05-02T10:15:00+00:00'),
        ], $bus);

        $handler(new SyncHelloAssoFormMessage(HelloAssoConfig::FORM_TYPE_EVENT, 'archilan-spring-2027'));
    }

    public function testSyncDispatchesPaidMessageOnceWhenExistingOrderTransitionsToPaid(): void
    {
        $now = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $this->entityManager->persist(HelloAssoOrder::fromHelloAsso(
            123456,
            HelloAssoConfig::FORM_TYPE_EVENT,
            'archilan-spring-2027',
            'Pending',
            2500,
            'payer@example.org',
            'Ada',
            'Lovelace',
            null,
            $now,
        ));
        $this->entityManager->flush();
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->with(self::isInstanceOf(HelloAssoOrderPaidMessage::class))
            ->willReturn(new Envelope(new \stdClass()));
        $handler = $this->handler([
            new MockResponse('{"access_token":"server-token"}'),
            $this->itemsResponse(123456, 'payer@example.org', '2026-05-02T10:15:00+00:00'),
        ], $bus);

        $handler(new SyncHelloAssoFormMessage(HelloAssoConfig::FORM_TYPE_EVENT, 'archilan-spring-2027'));
    }

    public function testSyncDoesNotRedispatchWhenExistingOrderWasAlreadyPaid(): void
    {
        $paidAt = new \DateTimeImmutable('2026-05-01T10:00:00+00:00');
        $this->entityManager->persist(HelloAssoOrder::fromHelloAsso(
            123456,
            HelloAssoConfig::FORM_TYPE_EVENT,
            'archilan-spring-2027',
            'Processed',
            2500,
            'payer@example.org',
            'Ada',
            'Lovelace',
            $paidAt,
            $paidAt,
        ));
        $this->entityManager->flush();
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');
        $handler = $this->handler([
            new MockResponse('{"access_token":"server-token"}'),
            $this->itemsResponse(123456, 'payer@example.org', '2026-05-02T10:15:00+00:00'),
        ], $bus);

        $handler(new SyncHelloAssoFormMessage(HelloAssoConfig::FORM_TYPE_EVENT, 'archilan-spring-2027'));
    }

    public function testSyncRethrowsWhenPaidMessageDispatchFails(): void
    {
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())
            ->method('dispatch')
            ->willThrowException(new \RuntimeException('bus down'));
        $handler = $this->handler([
            new MockResponse('{"access_token":"server-token"}'),
            $this->itemsResponse(123456, 'payer@example.org', '2026-05-02T10:15:00+00:00'),
        ], $bus);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bus down');

        $handler(new SyncHelloAssoFormMessage(HelloAssoConfig::FORM_TYPE_EVENT, 'archilan-spring-2027'));
    }

    public function testTransientFetchFailureIsLoggedAndRethrownForMessengerRetry(): void
    {
        $handler = $this->handler([
            new MockResponse('{"access_token":"server-token"}'),
            new MockResponse('{"message":"temporary outage"}', ['http_code' => 503]),
        ]);

        $this->expectException(\Throwable::class);

        try {
            $handler(new SyncHelloAssoFormMessage(HelloAssoConfig::FORM_TYPE_EVENT, 'archilan-spring-2027'));
        } finally {
            $logs = $this->entityManager->getRepository(HelloAssoSyncLog::class)->findAll();
            self::assertCount(1, $logs);
            self::assertInstanceOf(HelloAssoSyncLog::class, $logs[0]);
            self::assertFalse($logs[0]->isSuccess());
            self::assertIsString($logs[0]->getErrorMessage());
            self::assertStringContainsString('503', $logs[0]->getErrorMessage());
        }
    }

    /**
     * @param list<MockResponse> $responses
     */
    private function handler(array $responses, ?MessageBusInterface $bus = null): SyncHelloAssoFormHandler
    {
        $client = new HelloAssoHttpClient(
            new HelloAssoConfig('client-id', 'client-secret', 'archilan', true),
            new MockHttpClient($responses),
        );

        $orderRepository = new DoctrineHelloAssoOrderRepository($this->entityManager);
        $syncLogRepository = new DoctrineHelloAssoSyncLogRepository($this->entityManager, $this->entityManager->getConnection());

        return new SyncHelloAssoFormHandler($client, $orderRepository, $syncLogRepository, $bus ?? new class implements MessageBusInterface {
            public function dispatch(object $message, array $stamps = []): Envelope
            {
                return new Envelope($message, $stamps);
            }
        }, new NullLogger());
    }

    private function itemsResponse(int $orderId, ?string $payerEmail, ?string $paidAt): MockResponse
    {
        return new MockResponse(json_encode([
            'data' => [[
                'order' => ['id' => $orderId, 'date' => $paidAt],
                'state' => null !== $paidAt ? 'Processed' : 'Pending',
                'amount' => 2500,
                'payer' => [
                    'email' => $payerEmail,
                    'firstName' => 'Ada',
                    'lastName' => 'Lovelace',
                ],
                'user' => ['firstName' => 'Ada', 'lastName' => 'Lovelace'],
            ]],
            'pagination' => ['totalCount' => 1],
        ], JSON_THROW_ON_ERROR));
    }
}
