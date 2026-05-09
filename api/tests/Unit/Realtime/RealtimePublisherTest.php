<?php

declare(strict_types=1);

namespace App\Tests\Unit\Realtime;

use App\Realtime\Application\RealtimePublisher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

final class RealtimePublisherTest extends TestCase
{
    public function testPublishesPublicSeatCounterUpdate(): void
    {
        $publishedUpdate = null;
        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->once())->method('publish')
            ->willReturnCallback(static function (Update $update) use (&$publishedUpdate): string {
                $publishedUpdate = $update;

                return '';
            });

        $publisher = new RealtimePublisher($hub, $this->createStub(LoggerInterface::class));
        $publisher->seatCounter('event-123', 17);

        self::assertInstanceOf(Update::class, $publishedUpdate);
        self::assertSame(['https://archilan.fr/events/event-123/seat-counter'], $publishedUpdate->getTopics());
        self::assertFalse($publishedUpdate->isPrivate());
        self::assertSame(['eventId' => 'event-123', 'remainingSeats' => 17], json_decode($publishedUpdate->getData(), true));
    }

    public function testPublishesPrivateAdminRegistrationFeedUpdate(): void
    {
        $publishedUpdate = null;
        $hub = $this->createMock(HubInterface::class);
        $hub->expects($this->once())->method('publish')
            ->willReturnCallback(static function (Update $update) use (&$publishedUpdate): string {
                $publishedUpdate = $update;

                return '';
            });

        $publisher = new RealtimePublisher($hub, $this->createStub(LoggerInterface::class));
        $publisher->adminRegistrationCreated('event-123', 'registration-456', new \DateTimeImmutable('2026-05-02T12:00:00+00:00'));

        self::assertInstanceOf(Update::class, $publishedUpdate);
        self::assertSame(['https://archilan.fr/events/event-123/registrations'], $publishedUpdate->getTopics());
        self::assertTrue($publishedUpdate->isPrivate());
        self::assertSame(
            [
                'type' => 'registration.reserved',
                'registrationId' => 'registration-456',
                'createdAt' => '2026-05-02T12:00:00+00:00',
            ],
            json_decode($publishedUpdate->getData(), true),
        );
    }

    public function testPublishFailureIsLoggedAndDoesNotBubble(): void
    {
        $hub = $this->createStub(HubInterface::class);
        $hub->method('publish')->willThrowException(new \RuntimeException('hub unavailable'));

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('realtime.seat_counter_publish_failed', self::arrayHasKey('error'));

        $publisher = new RealtimePublisher($hub, $logger);
        $publisher->seatCounter('event-123', 17);
    }
}
