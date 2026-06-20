<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Domain\Run;
use PHPUnit\Framework\TestCase;

final class RunCompleteTest extends TestCase
{
    public function testCompleteSetsCompletedAndClearsConnection(): void
    {
        $now = new \DateTimeImmutable('2026-06-20T10:00:00+00:00');
        $run = Run::create('owner-1', 'My run', $now);
        $run->setSessionId('sess-1');
        $run->start($now);
        $run->markRunning('runner.example.com', 38281, $now, 'deadbeef12345678');

        $run->complete($now);

        self::assertSame(Run::STATUS_COMPLETED, $run->getStatus());
        self::assertNull($run->getConnectionHost());
        self::assertNull($run->getConnectionPort());
        self::assertNull($run->getConnectionPassword());
    }

    public function testCompleteThrowsWhenRunNotActive(): void
    {
        $now = new \DateTimeImmutable('2026-06-20T10:00:00+00:00');
        $run = Run::create('owner-1', 'My run', $now); // draft

        $this->expectException(\DomainException::class);
        $run->complete($now);
    }
}
