<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Application\RecordSessionGeneratedOutput;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class RecordSessionGeneratedOutputTest extends TestCase
{
    public function testStoresKeyAndPersistsWhenSessionExists(): void
    {
        $session = Session::create('sess-1', 'event-1', new \DateTimeImmutable());

        $repo = $this->createMock(SessionRepositoryInterface::class);
        $repo->method('findById')->willReturn($session);
        $repo->expects(self::once())->method('persist')->with($session);
        $repo->expects(self::once())->method('flush');

        (new RecordSessionGeneratedOutput($repo))->execute('sess-1', 'sess-1/output/archive.zip');

        self::assertSame('sess-1/output/archive.zip', $session->getGeneratedOutputKey());
    }

    public function testNoOpWhenKeyIsEmpty(): void
    {
        $repo = $this->createMock(SessionRepositoryInterface::class);
        $repo->expects(self::never())->method('findById');
        $repo->expects(self::never())->method('persist');
        $repo->expects(self::never())->method('flush');

        (new RecordSessionGeneratedOutput($repo))->execute('sess-1', '');
    }

    public function testNoOpWhenSessionMissing(): void
    {
        $repo = $this->createMock(SessionRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('persist');
        $repo->expects(self::never())->method('flush');

        (new RecordSessionGeneratedOutput($repo))->execute('sess-1', 'sess-1/output/archive.zip');
    }
}
