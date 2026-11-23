<?php

declare(strict_types=1);

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;

/** Story 33.29 control PR: a PHPStan error on purpose, the test itself passes. Never merged. */
final class CiControlTest extends TestCase
{
    public function testItPasses(): void
    {
        self::assertSame(2, $this->two());
    }

    private function two(): int
    {
        return 1 + 1;
    }

    /** @return int */
    public function wrong(): int
    {
        return strlen(...);
    }
}
