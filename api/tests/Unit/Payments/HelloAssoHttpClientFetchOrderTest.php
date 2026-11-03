<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payments;

use App\Payments\Application\Support\HelloAssoConfig;
use App\Payments\Infrastructure\Http\HelloAssoHttpClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

/**
 * The webhook trusts nothing but this read: the form and the paid state come from HelloAsso's order.
 */
final class HelloAssoHttpClientFetchOrderTest extends TestCase
{
    public function testAnOrderWithAProcessedItemIsPaid(): void
    {
        $order = $this->fetch(['id' => 42, 'formSlug' => 'adhesion-2026', 'formType' => 'Membership', 'items' => [['state' => 'Refunded'], ['state' => 'Processed']]]);

        self::assertNotNull($order);
        self::assertTrue($order['isPaid']);
        self::assertSame('adhesion-2026', $order['formSlug']);
        self::assertSame('Membership', $order['formType']);
    }

    public function testAnOrderWithoutAProcessedItemIsNotPaid(): void
    {
        $order = $this->fetch(['id' => 42, 'formSlug' => 'adhesion-2026', 'items' => [['state' => 'Refunded']]]);

        self::assertNotNull($order);
        self::assertFalse($order['isPaid']);
    }

    public function testAnOrderWithoutItemsIsNotPaid(): void
    {
        $order = $this->fetch(['id' => 42]);

        self::assertNotNull($order);
        self::assertFalse($order['isPaid']);
        self::assertNull($order['formSlug']);
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array{orderId: int, amountCents: int, payerEmail: string|null, payerFirstName: string|null, payerLastName: string|null, paidAt: \DateTimeImmutable|null, formSlug: string|null, formType: string|null, isPaid: bool}|null
     */
    private function fetch(array $body): ?array
    {
        $client = new HelloAssoHttpClient(
            new HelloAssoConfig('client-id', 'client-secret', 'archilan', true),
            new MockHttpClient([new JsonMockResponse($body)]),
        );

        return $client->fetchOrder(42, 'token');
    }
}
