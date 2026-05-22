<?php

declare(strict_types=1);

namespace App\Tests\Unit\WeeklyRuns;

use App\WeeklyRuns\Application\LaunchWeeklyEntry;
use App\WeeklyRuns\Application\WeeklyRunnerGatewayInterface;
use App\WeeklyRuns\Domain\WeeklyEntry;
use App\WeeklyRuns\Domain\WeeklyEntryRepositoryInterface;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyRunRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class LaunchWeeklyEntryTest extends TestCase
{
    private static MockClock $clock;
    private string $workspaceDir;
    private string $seedFile;

    public static function setUpBeforeClass(): void
    {
        self::$clock = new MockClock(new \DateTimeImmutable('2026-05-20T10:00:00', new \DateTimeZone('UTC')));
    }

    protected function setUp(): void
    {
        $this->workspaceDir = sys_get_temp_dir().'/launch_weekly_entry_test_'.uniqid('', true);
        mkdir($this->workspaceDir.'/run-1/output', 0755, true);
        $this->seedFile = $this->workspaceDir.'/run-1/output/world.archipelago';
        file_put_contents($this->seedFile, 'fake-seed-content');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->workspaceDir);
    }

    public function testInvokeThrowsWhenRunNotGenerated(): void
    {
        $run = $this->makeRun(generatedSeedPath: null);

        $runs = $this->createStub(WeeklyRunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        $entries = $this->createMock(WeeklyEntryRepositoryInterface::class);
        $entries->expects(self::never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('run_not_generated');

        $this->makeHandler($runs, $entries, $this->createStub(WeeklyRunnerGatewayInterface::class))
            ->execute('run-1', 'entry-1', 'user-1');
    }

    public function testInvokeThrowsWhenSessionAlreadyStarted(): void
    {
        $run = $this->makeRun();
        $entry = $this->makeEntry(externalSessionId: 'existing-session');

        $runs = $this->createStub(WeeklyRunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        $entries = $this->createStub(WeeklyEntryRepositoryInterface::class);
        $entries->method('findById')->willReturn($entry);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('session_already_started');

        $this->makeHandler($runs, $entries, $this->createStub(WeeklyRunnerGatewayInterface::class))
            ->execute('run-1', 'entry-1', 'user-1');
    }

    public function testInvokeThrowsForbiddenWhenEntryBelongsToDifferentUser(): void
    {
        $run = $this->makeRun();
        $entry = new WeeklyEntry(
            id: 'entry-1',
            weeklyRunId: 'run-1',
            userId: 'other-user',
            attemptNumber: 1,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
        );

        $runs = $this->createStub(WeeklyRunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        $entries = $this->createStub(WeeklyEntryRepositoryInterface::class);
        $entries->method('findById')->willReturn($entry);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('forbidden');

        $this->makeHandler($runs, $entries, $this->createStub(WeeklyRunnerGatewayInterface::class))
            ->execute('run-1', 'entry-1', 'user-1');
    }

    public function testInvokeLaunchesFromPreGeneratedSeed(): void
    {
        $run = $this->makeRun();
        $entry = $this->makeEntry();

        $runs = $this->createStub(WeeklyRunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        $entries = $this->createMock(WeeklyEntryRepositoryInterface::class);
        $entries->method('findById')->willReturn($entry);
        $entries->expects(self::once())->method('flush');

        $capturedSeedPath = null;
        $gateway = $this->createMock(WeeklyRunnerGatewayInterface::class);
        $gateway->expects(self::once())->method('launchFromSeed')
            ->willReturnCallback(static function (string $entryId, string $seedFilePath) use (&$capturedSeedPath): array {
                $capturedSeedPath = $seedFilePath;

                return [
                    'externalSessionId' => 'sess-1',
                    'connectionInfo' => ['host' => 'runner.test', 'port' => 38281, 'password' => null],
                    'bridgePort' => 5001,
                ];
            });

        $result = $this->makeHandler($runs, $entries, $gateway)->execute('run-1', 'entry-1', 'user-1');

        self::assertSame('entry-1', $result['entryId']);
        self::assertSame('sess-1', $result['externalSessionId']);
        self::assertSame('runner.test', $result['connectionInfo']['host']);
        self::assertNotNull($capturedSeedPath);
        self::assertStringEndsWith('world.archipelago', $capturedSeedPath);
        self::assertFileExists($capturedSeedPath);
    }

    public function testFlushFailureCallsTerminate(): void
    {
        $run = $this->makeRun();
        $entry = $this->makeEntry();

        $runs = $this->createStub(WeeklyRunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        $entries = $this->createStub(WeeklyEntryRepositoryInterface::class);
        $entries->method('findById')->willReturn($entry);
        $entries->method('flush')->willThrowException(new \RuntimeException('db error'));

        $gateway = $this->createMock(WeeklyRunnerGatewayInterface::class);
        $gateway->method('launchFromSeed')->willReturn([
            'externalSessionId' => 'sess-1',
            'connectionInfo' => ['host' => 'runner.test', 'port' => 38281, 'password' => null],
            'bridgePort' => 5001,
        ]);
        $gateway->expects(self::once())->method('terminate')->with('sess-1');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db error');

        $this->makeHandler($runs, $entries, $gateway)->execute('run-1', 'entry-1', 'user-1');
    }

    private function makeRun(?string $generatedSeedPath = ''): WeeklyRun
    {
        $now = new \DateTimeImmutable('2026-05-19T00:00:00+00:00');
        $run = new WeeklyRun(
            id: 'run-1',
            templateId: 'template-1',
            weekYear: 2026,
            weekNumber: 20,
            seed: 'archilan-weekly-2026-20',
            status: WeeklyRun::STATUS_ACTIVE,
            startedAt: $now,
            createdAt: $now,
        );

        if ('' === $generatedSeedPath) {
            $run->markGenerated($this->seedFile);
        } elseif (null !== $generatedSeedPath) {
            $run->markGenerated($generatedSeedPath);
        }

        return $run;
    }

    private function makeEntry(?string $externalSessionId = null): WeeklyEntry
    {
        $now = new \DateTimeImmutable('2026-05-19T00:00:00+00:00');

        return new WeeklyEntry(
            id: 'entry-1',
            weeklyRunId: 'run-1',
            userId: 'user-1',
            attemptNumber: 1,
            createdAt: $now,
            updatedAt: $now,
            externalSessionId: $externalSessionId,
        );
    }

    private function makeHandler(
        WeeklyRunRepositoryInterface $runs,
        WeeklyEntryRepositoryInterface $entries,
        WeeklyRunnerGatewayInterface $gateway,
    ): LaunchWeeklyEntry {
        return new LaunchWeeklyEntry($runs, $entries, $gateway, self::$clock, $this->workspaceDir);
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $item) {
            if ('.' === $item || '..' === $item) {
                continue;
            }
            $path = $dir.'/'.$item;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
