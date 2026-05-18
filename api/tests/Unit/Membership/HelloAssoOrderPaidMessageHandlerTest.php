<?php

declare(strict_types=1);

namespace App\Tests\Unit\Membership;

use App\Membership\Application\Handler\HelloAssoOrderPaidMessageHandler;
use App\Membership\Application\ProcessHelloAssoMembershipPaymentInterface;
use App\Payments\Application\Message\HelloAssoOrderPaidMessage;
use PHPUnit\Framework\TestCase;

final class HelloAssoOrderPaidMessageHandlerTest extends TestCase
{
    public function testInvokeSkipsWhenFormSlugDoesNotMatch(): void
    {
        $service = $this->createMock(ProcessHelloAssoMembershipPaymentInterface::class);
        $service->expects(self::never())->method('process');

        $handler = new HelloAssoOrderPaidMessageHandler($service, 'membership-form-slug');

        $handler(new HelloAssoOrderPaidMessage(
            'order-1',
            'other-form-slug',
            'payer@example.org',
            new \DateTimeImmutable(),
        ));
    }

    public function testInvokeCallsProcessWhenFormSlugMatches(): void
    {
        $paidAt = new \DateTimeImmutable('2026-05-16T10:00:00+00:00');
        $message = new HelloAssoOrderPaidMessage(
            'order-1',
            'membership-form-slug',
            'payer@example.org',
            $paidAt,
        );

        $service = $this->createMock(ProcessHelloAssoMembershipPaymentInterface::class);
        $service->expects(self::once())->method('process')->with(
            'order-1',
            'payer@example.org',
            $paidAt,
        );

        $handler = new HelloAssoOrderPaidMessageHandler($service, 'membership-form-slug');

        $handler($message);
    }
}
