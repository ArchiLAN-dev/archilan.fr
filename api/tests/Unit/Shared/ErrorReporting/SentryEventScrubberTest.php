<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\ErrorReporting;

use App\Shared\Infrastructure\ErrorReporting\SentryEventScrubber;
use PHPUnit\Framework\TestCase;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionDataBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;

final class SentryEventScrubberTest extends TestCase
{
    public function testAClientErrorStaysOut(): void
    {
        $scrub = new SentryEventScrubber();

        self::assertNull($scrub(Event::createEvent(), EventHint::fromArray(['exception' => new NotFoundHttpException()])));
        self::assertNull($scrub(Event::createEvent(), EventHint::fromArray(['exception' => new AccessDeniedHttpException()])));
        self::assertNotNull($scrub(Event::createEvent(), EventHint::fromArray(['exception' => new ServiceUnavailableHttpException()])));
        self::assertNotNull($scrub(Event::createEvent(), EventHint::fromArray(['exception' => new \RuntimeException('boom')])));
        self::assertNotNull($scrub(Event::createEvent(), null));
    }

    public function testTheRequestKeepsItsMethodAndPathOnly(): void
    {
        $event = Event::createEvent();
        $event->setRequest([
            'method' => 'POST',
            'url' => 'https://api.archilan.fr/api/v1/internal/sessions/abc/feed-push?token=secret',
            'headers' => ['X-Internal-Secret' => ['s3cr3t'], 'Cookie' => ['__Host-session=x']],
            'cookies' => ['__Host-session' => 'x'],
            'data' => ['password' => 'hunter2'],
        ]);

        $scrubbed = (new SentryEventScrubber())($event, null);

        self::assertNotNull($scrubbed);
        self::assertSame(['method' => 'POST', 'url' => 'https://api.archilan.fr/api/v1/internal/sessions/abc/feed-push'], $scrubbed->getRequest());
    }

    public function testAnEmailIsMaskedEverywhere(): void
    {
        $event = Event::createEvent();
        $event->setMessage('Mail to alice@example.org failed', [], 'Mail to alice@example.org failed');
        $event->setExceptions([new ExceptionDataBag(new \RuntimeException('no account for bob.x+tag@mail.example.fr'))]);
        $event->setExtra(['monolog.context' => ['email' => 'carol@example.org', 'userId' => 'u1', 'nested' => ['to' => 'dave@example.org']]]);

        $scrubbed = (new SentryEventScrubber())($event, null);

        self::assertNotNull($scrubbed);
        self::assertSame('Mail to [email] failed', $scrubbed->getMessage());
        self::assertSame('Mail to [email] failed', $scrubbed->getMessageFormatted());
        self::assertSame('no account for [email]', $scrubbed->getExceptions()[0]->getValue());
        self::assertSame(['monolog.context' => ['email' => '[email]', 'userId' => 'u1', 'nested' => ['to' => '[email]']]], $scrubbed->getExtra());
    }
}
