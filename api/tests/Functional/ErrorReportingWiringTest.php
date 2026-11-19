<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Shared\Infrastructure\ErrorReporting\SentryEventScrubber;
use Sentry\Monolog\LogToSentryIssueHandler;
use Sentry\State\HubInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Yaml\Yaml;

/**
 * Story 33.32: without SENTRY_DSN (dev, test, and production until it is set), the SDK has nowhere to send; when it
 * does send, every event goes through the scrubber.
 */
final class ErrorReportingWiringTest extends WebTestCase
{
    public function testNothingIsSentWithoutADsnAndEveryEventIsScrubbed(): void
    {
        self::bootKernel();
        $hub = self::getContainer()->get(HubInterface::class);
        self::assertInstanceOf(HubInterface::class, $hub);

        $options = $hub->getClient()?->getOptions();
        self::assertNotNull($options);
        self::assertNull($options->getDsn());
        self::assertFalse($options->shouldSendDefaultPii());
        self::assertInstanceOf(SentryEventScrubber::class, $options->getBeforeSendCallback());
    }

    public function testAnErrorLoggedWithoutExceptionKeepsItsContext(): void
    {
        // The handler serves in prod only (monolog.yaml): the test container drops it, so its wiring is read. Off by
        // default in the SDK: the session id of `session.crash.unexpected_state` never reached Bugsink.
        $config = Yaml::parseFile(\dirname(__DIR__, 2).'/config/packages/sentry.yaml', Yaml::PARSE_CONSTANT | Yaml::PARSE_CUSTOM_TAGS);
        self::assertIsArray($config);
        $services = $config['services'] ?? null;
        self::assertIsArray($services);
        $handler = $services[LogToSentryIssueHandler::class] ?? null;
        self::assertIsArray($handler);
        $arguments = $handler['arguments'] ?? null;
        self::assertIsArray($arguments);

        self::assertTrue($arguments['$fillExtraContext'] ?? null);
    }
}
