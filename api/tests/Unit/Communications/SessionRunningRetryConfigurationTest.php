<?php

declare(strict_types=1);

namespace App\Tests\Unit\Communications;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;

final class SessionRunningRetryConfigurationTest extends TestCase
{
    public function testAsyncTransportRetriesThreeTimesWithExponentialBackoffAndFailureTransport(): void
    {
        $config = Yaml::parseFile(__DIR__.'/../../../config/packages/messenger.yaml');

        self::assertIsArray($config);
        $framework = $config['framework'] ?? null;
        self::assertIsArray($framework);
        $messenger = $framework['messenger'] ?? null;
        self::assertIsArray($messenger);

        self::assertSame('async_failed', $messenger['failure_transport'] ?? null);
        $transports = $messenger['transports'] ?? null;
        self::assertIsArray($transports);
        self::assertArrayHasKey('async_failed', $transports);

        $asyncTransport = $transports['async'] ?? null;
        self::assertIsArray($asyncTransport);
        $retryStrategy = $asyncTransport['retry_strategy'] ?? null;
        self::assertIsArray($retryStrategy);
        self::assertSame(3, $retryStrategy['max_retries']);
        self::assertSame(1000, $retryStrategy['delay']);
        self::assertSame(2, $retryStrategy['multiplier']);
    }
}
