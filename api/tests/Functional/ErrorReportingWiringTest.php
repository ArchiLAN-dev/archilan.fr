<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Sentry\State\HubInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Story 33.32: without SENTRY_DSN (dev, test, and production until it is set), the SDK has nowhere to send.
 */
final class ErrorReportingWiringTest extends KernelTestCase
{
    public function testNothingIsSentWithoutADsn(): void
    {
        self::bootKernel();
        $hub = self::getContainer()->get(HubInterface::class);
        self::assertInstanceOf(HubInterface::class, $hub);

        $options = $hub->getClient()?->getOptions();
        self::assertNotNull($options);
        self::assertNull($options->getDsn());
        self::assertFalse($options->shouldSendDefaultPii());
    }
}
