<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Application\Support\OrchestratorWebhookFreshness;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class OrchestratorWebhookFreshnessTest extends TestCase
{
    /** @return iterable<string, array{mixed, bool}> */
    public static function timestamps(): iterable
    {
        yield 'same instant' => ['2026-11-04T12:00:00Z', true];
        yield 'nanoseconds, as Go writes them' => ['2026-11-04T12:00:00.123456789Z', true];
        yield 'short fraction' => ['2026-11-04T12:00:00.5Z', true];
        yield 'offset' => ['2026-11-04T13:00:00+01:00', true];
        yield 'exactly the tolerance behind' => ['2026-11-04T11:55:00Z', true];
        yield 'exactly the tolerance ahead' => ['2026-11-04T12:05:00Z', true];
        yield 'one second too old' => ['2026-11-04T11:54:59Z', false];
        yield 'one second too far ahead' => ['2026-11-04T12:05:01Z', false];
        yield 'an offset does not hide the drift' => ['2026-11-04T12:00:00+01:00', false];
        yield 'absent' => [null, false];
        yield 'empty' => ['', false];
        yield 'relative format' => ['now', false];
        yield 'no zone' => ['2026-11-04T12:00:00', false];
        yield 'not a date' => ['9999-99-99T99:99:99Z', false];
        yield 'trailing line break' => ["2026-11-04T12:00:00Z\n", false];
        yield 'number' => [1_793_793_600, false];
    }

    #[DataProvider('timestamps')]
    public function testAccepts(mixed $timestamp, bool $accepted): void
    {
        $freshness = new OrchestratorWebhookFreshness(new MockClock(new \DateTimeImmutable('2026-11-04T12:00:00+00:00')));

        self::assertSame($accepted, $freshness->accepts($timestamp));
    }
}
