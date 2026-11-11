<?php

declare(strict_types=1);

namespace App\Tests\Unit\Streaming;

use App\Events\Domain\Entity\Event;
use App\Registrations\Domain\Entity\Registration;
use App\Streaming\Application\Support\MediaPublishCredentials;
use PHPUnit\Framework\TestCase;

/** Story 47.5: the path and key a registrant publishes to the LAN media server with. */
final class MediaPublishCredentialsTest extends TestCase
{
    public function testThePathIsOpaqueStableAndPerRegistration(): void
    {
        $credentials = new MediaPublishCredentials('secret');

        $path = $credentials->pathFor('reg-1');

        self::assertMatchesRegularExpression(MediaPublishCredentials::PATH_PATTERN, $path);
        self::assertSame($path, $credentials->pathFor('reg-1'));
        self::assertNotSame($path, $credentials->pathFor('reg-2'));
        self::assertStringNotContainsString('reg-1', $path);
        self::assertNotSame($path, new MediaPublishCredentials('other')->pathFor('reg-1'));
    }

    public function testTheKeyChangesWithItsVersionAndNeverHoldsAColon(): void
    {
        $credentials = new MediaPublishCredentials('secret');

        $key = $credentials->keyFor('event-1', 'reg-1', 0);

        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $key);
        self::assertSame($key, $credentials->keyFor('event-1', 'reg-1', 0));
        self::assertNotSame($key, $credentials->keyFor('event-1', 'reg-1', 1));
        self::assertNotSame($key, $credentials->keyFor('event-1', 'reg-2', 0));
        self::assertNotSame($key, $credentials->keyFor('event-2', 'reg-1', 0));
    }

    public function testTheAddressesPointAtTheLanServerPorts(): void
    {
        self::assertSame('https://media.lan.archilan.fr:8889/p-abc/whip', MediaPublishCredentials::whipUrl('media.lan.archilan.fr', 'p-abc'));
        self::assertSame('srt://media.lan.archilan.fr:8890?streamid=publish:p-abc:archilan:k3y&pkt_size=1316', MediaPublishCredentials::srtUrl('media.lan.archilan.fr', 'p-abc', 'k3y'));
    }

    public function testOnlyABareHostNameIsAMediaServer(): void
    {
        foreach (['media.lan.archilan.fr', 'mtx.local', 'a1.b2'] as $valid) {
            self::assertTrue(Event::isValidMediaServerHost($valid), $valid);
        }
        foreach (['https://media.lan.archilan.fr', 'media.lan.archilan.fr:8889', 'localhost', 'Media.Lan.fr', '-media.lan', 'media..lan', 'media.lan/whip', ''] as $invalid) {
            self::assertFalse(Event::isValidMediaServerHost($invalid), $invalid);
        }
    }

    public function testAnEventPointsAtItsMediaServerOrStops(): void
    {
        $now = new \DateTimeImmutable('2026-11-11 10:00');
        $event = Event::draft('LAN', 'desc', $now->modify('+10 days'), $now->modify('+11 days'), 'Lille', 40, $now, $now->modify('+9 days'), true, $now);

        $event->pointMediaServer('  Media.LAN.archilan.fr ', $now);
        self::assertSame('media.lan.archilan.fr', $event->getMediaServerHost());

        $event->pointMediaServer('', $now);
        self::assertNull($event->getMediaServerHost());

        $this->expectExceptionMessage('event_media_server_invalid');
        $event->pointMediaServer('https://media.lan.archilan.fr', $now);
    }

    public function testANewKeyIsForAReservedRegistrationOnly(): void
    {
        $now = new \DateTimeImmutable('2026-11-11 10:00');
        $registration = new Registration('reg-1', 'event-1', 'user-1', Registration::STATUS_RESERVED, $now, $now);

        $registration->regenerateMediaKey($now);
        self::assertSame(1, $registration->getMediaKeyVersion());

        $registration->cancel($now);
        $this->expectException(\DomainException::class);
        $registration->regenerateMediaKey($now);
    }
}
