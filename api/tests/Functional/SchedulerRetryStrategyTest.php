<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Retry\RetryStrategyInterface;

/**
 * A scheduled message that fails must never be sent back to the scheduler for a retry: the scheduler
 * transport cannot receive messages, and the attempt threw "SchedulerTransport cannot send messages"
 * out of the worker. The api-worker consumes `async` and `scheduler_default` together, so any failing
 * scheduled task - the HelloAsso sync with the API down, a nightly apworld check with the orchestrator
 * unreachable - took down the consumption of every async message until the container restarted.
 *
 * With no retry, a failed scheduled message goes to the failure transport, and runs again at its next
 * slot anyway.
 */
final class SchedulerRetryStrategyTest extends KernelTestCase
{
    public function testAScheduledMessageThatFailsIsNeverSentBackToTheScheduler(): void
    {
        self::bootKernel();

        $locator = self::getContainer()->get('messenger.retry_strategy_locator');
        self::assertInstanceOf(\Psr\Container\ContainerInterface::class, $locator);
        $strategy = $locator->get('scheduler_default');
        self::assertInstanceOf(RetryStrategyInterface::class, $strategy);

        self::assertFalse($strategy->isRetryable(new Envelope(new \stdClass()), new \RuntimeException('handler failed')));
    }
}
