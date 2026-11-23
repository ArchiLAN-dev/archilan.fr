<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Story 33.29 control PR: a test that fails on purpose. Never merged. */
final class CiControlTest extends TestCase
{
    public function testItFailsOnPurpose(): void
    {
        self::assertSame(1, 2);
    }
}
