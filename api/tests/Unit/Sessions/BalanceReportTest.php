<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Application\Command\BalanceGenerationRef;
use App\Sessions\Application\Command\RecordBalanceDryGeneration;
use App\Sessions\Domain\Entity\BalanceReport;
use PHPUnit\Framework\TestCase;

/**
 * Story 56.3, fixed after the 1.1.0 review: a dry generation's result only lands on the generation it belongs to.
 */
final class BalanceReportTest extends TestCase
{
    public function testAReportAwaitsOnlyTheGenerationItStarted(): void
    {
        $report = $this->report('2026-11-24T10:00:00+00:00');
        $first = $report->attempt();

        $report->restart([], 'fingerprint-2', new \DateTimeImmutable('2026-11-24T11:30:00+00:00'));

        self::assertFalse($report->awaits($first), 'the result of the first generation is late');
        self::assertTrue($report->awaits($report->attempt()));
        self::assertTrue($report->awaits(null), 'a generation started before 1.1.1 carries no attempt');
    }

    public function testAFinishedReportIgnoresLaterResults(): void
    {
        $report = $this->report('2026-11-24T10:00:00+00:00');
        $report->complete([['name' => 'Zoe', 'locations' => 10, 'ownProgression' => 3, 'hostedProgression' => 2]], new \DateTimeImmutable('2026-11-24T10:05:00+00:00'));

        $report->fail('late crash', new \DateTimeImmutable('2026-11-24T10:06:00+00:00'));
        $report->recordOutput('sessions/late.zip');
        $report->complete([], new \DateTimeImmutable('2026-11-24T10:07:00+00:00'));

        self::assertSame(BalanceReport::STATUS_DONE, $report->getStatus());
        self::assertNull($report->getError());
        self::assertNull($report->getOutputKey());
        self::assertCount(1, $report->getMeasures() ?? []);
        self::assertFalse($report->awaits($report->attempt()));
    }

    public function testTheOrchestratorSessionIdCarriesTheAttempt(): void
    {
        $report = $this->report('2026-11-24T10:00:00+00:00');

        $sessionId = RecordBalanceDryGeneration::orchestratorSessionId($report);

        self::assertSame('balance-event-e1-a'.$report->attempt(), $sessionId);
        self::assertEquals(new BalanceGenerationRef('event-e1', $report->attempt()), RecordBalanceDryGeneration::parseSessionId($sessionId));
        self::assertEquals(new BalanceGenerationRef('event-e1', null), RecordBalanceDryGeneration::parseSessionId('balance-event-e1'));
        self::assertNull(RecordBalanceDryGeneration::parseSessionId('session-1'));
    }

    private function report(string $at): BalanceReport
    {
        return BalanceReport::start(BalanceReport::SCOPE_EVENT, 'e1', [], 'fingerprint', new \DateTimeImmutable($at));
    }
}
