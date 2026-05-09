<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Infrastructure\PortPool;
use PHPUnit\Framework\TestCase;

final class PortPoolTest extends TestCase
{
    public function testAllocatesPortsInOrder(): void
    {
        $pool = new PortPool(100, 102);

        self::assertSame(100, $pool->allocate());
        self::assertSame(101, $pool->allocate());
        self::assertSame(102, $pool->allocate());
    }

    public function testReturnsNullWhenExhausted(): void
    {
        $pool = new PortPool(100, 100);

        $pool->allocate();
        self::assertNull($pool->allocate());
    }

    public function testReleasedPortBecomesAvailable(): void
    {
        $pool = new PortPool(100, 101);

        $p1 = $pool->allocate();
        self::assertNotNull($p1);
        $pool->allocate();

        $pool->release($p1);
        self::assertSame(1, $pool->availableCount());
        self::assertSame($p1, $pool->allocate());
    }

    public function testReleaseIgnoresPortOutsideManagedRange(): void
    {
        $pool = new PortPool(100, 101);
        $pool->allocate();

        $pool->release(9999);

        self::assertSame(1, $pool->availableCount());
    }

    public function testReleaseIgnoresUnallocatedPort(): void
    {
        $pool = new PortPool(100, 101);

        $pool->release(100);

        self::assertSame(2, $pool->availableCount());
    }

    public function testMarkAllocatedRemovesPortsFromAvailable(): void
    {
        $pool = new PortPool(100, 103);

        $pool->markAllocated([101, 103]);

        self::assertSame(2, $pool->availableCount());
        self::assertSame([101, 103], $pool->getAllocated());
    }

    public function testMarkAllocatedIgnoresPortsOutsideRange(): void
    {
        $pool = new PortPool(100, 102);

        $pool->markAllocated([50, 200]);

        self::assertSame(3, $pool->availableCount());
        self::assertSame([], $pool->getAllocated());
    }

    public function testMarkAllocatedIsIdempotentForAlreadyAllocated(): void
    {
        $pool = new PortPool(100, 102);
        $pool->allocate(); // 100 now allocated

        $pool->markAllocated([100]);

        self::assertSame(2, $pool->availableCount());
    }

    public function testInvalidRangeThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new PortPool(200, 100);
    }

    public function testSinglePortRange(): void
    {
        $pool = new PortPool(9000, 9000);

        self::assertSame(9000, $pool->allocate());
        self::assertNull($pool->allocate());
    }

    public function testAvailableCountDecreasesOnAllocate(): void
    {
        $pool = new PortPool(100, 104);

        self::assertSame(5, $pool->availableCount());
        $pool->allocate();
        self::assertSame(4, $pool->availableCount());
    }
}
