<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Payments\Application\HelloAssoConfig;
use App\Payments\Application\SyncHelloAssoFormHandler;
use App\Payments\Application\SyncHelloAssoFormMessage;
use App\Payments\Domain\HelloAssoOrder;
use App\Payments\Domain\HelloAssoSyncLog;
use App\Payments\Infrastructure\HelloAssoHttpClient;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class HelloAssoSyncHandlerTest extends KernelTestCase
{
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        self::bootKernel();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $metadata = [
            $this->entityManager->getClassMetadata(HelloAssoOrder::class),
            $this->entityManager->getClassMetadata(HelloAssoSyncLog::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testSyncFetchesHelloAssoItemsAndPersistsInternalOrders(): void
    {
        $handler = $this->handler([
            new MockResponse('{"access_token":"server-token"}'),
            new MockResponse(json_encode([
                'data' => [[
                    'order' => [
                        'id' => 123456,
                        'status' => 'Processed',
                        'amount' => ['total' => 2500],
                        'payer' => [
                            'email' => 'payer@example.org',
                            'firstName' => 'Ada',
                            'lastName' => 'Lovelace',
                        ],
                        'date' => '2026-05-02T10:15:00+00:00',
                    ],
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
    private function handler(array $responses): SyncHelloAssoFormHandler
    {
        $client = new HelloAssoHttpClient(
            new HelloAssoConfig('client-id', 'client-secret', 'archilan', true),
            new MockHttpClient($responses),
        );

        return new SyncHelloAssoFormHandler($client, $this->entityManager, new NullLogger());
    }
}
