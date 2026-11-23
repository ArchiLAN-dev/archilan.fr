<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Application\Command\RecordSessionGeneratedOutput;
use App\Sessions\Application\Message\MeasureSessionSlotsJob;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

final class RecordSessionGeneratedOutputTest extends TestCase
{
    public function testStoresKeyAndPersistsWhenSessionExists(): void
    {
        $session = Session::create('sess-1', 'event-1', new \DateTimeImmutable());

        $repo = $this->createMock(SessionRepositoryInterface::class);
        $repo->method('findById')->willReturn($session);
        $repo->expects(self::once())->method('persist')->with($session);
        $repo->expects(self::once())->method('flush');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::once())->method('dispatch')
            ->with(self::callback(static fn (object $m): bool => $m instanceof MeasureSessionSlotsJob && 'sess-1' === $m->sessionId))
            ->willReturnCallback(static fn (object $m): Envelope => new Envelope($m));

        new RecordSessionGeneratedOutput($repo, $bus)->execute('sess-1', 'sess-1/output/archive.zip');

        self::assertSame('sess-1/output/archive.zip', $session->getGeneratedOutputKey());
    }

    public function testNoOpWhenKeyIsEmpty(): void
    {
        $repo = $this->createMock(SessionRepositoryInterface::class);
        $repo->expects(self::never())->method('findById');
        $repo->expects(self::never())->method('persist');
        $repo->expects(self::never())->method('flush');

        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        new RecordSessionGeneratedOutput($repo, $bus)->execute('sess-1', '');
    }

    public function testNoOpWhenSessionMissing(): void
    {
        $repo = $this->createMock(SessionRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('persist');
        $repo->expects(self::never())->method('flush');
        $bus = $this->createMock(MessageBusInterface::class);
        $bus->expects(self::never())->method('dispatch');

        new RecordSessionGeneratedOutput($repo, $bus)->execute('sess-1', 'sess-1/output/archive.zip');
    }
}
