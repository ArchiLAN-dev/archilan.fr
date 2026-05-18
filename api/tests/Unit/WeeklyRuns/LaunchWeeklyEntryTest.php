<?php

declare(strict_types=1);

namespace App\Tests\Unit\WeeklyRuns;

use App\Shared\Infrastructure\MinioStorageInterface;
use App\WeeklyRuns\Application\LaunchWeeklyEntry;
use App\WeeklyRuns\Application\WeeklyRunnerGatewayInterface;
use App\WeeklyRuns\Domain\WeeklyEntry;
use App\WeeklyRuns\Domain\WeeklyRun;
use App\WeeklyRuns\Domain\WeeklyTemplate;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class LaunchWeeklyEntryTest extends TestCase
{
    private static MockClock $clock;

    public static function setUpBeforeClass(): void
    {
        self::$clock = new MockClock(new \DateTimeImmutable('2026-05-20T10:00:00', new \DateTimeZone('UTC')));
    }

    public function testInvokeThrowsWhenSessionAlreadyStarted(): void
    {
        $run = $this->makeRun();
        $entry = $this->makeEntry(externalSessionId: 'existing-session');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            static function (string $class) use ($run, $entry): ?object {
                return match ($class) {
                    WeeklyRun::class => $run,
                    WeeklyEntry::class => $entry,
                    default => null,
                };
            }
        );
        $em->expects(self::never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('session_already_started');

        $this->makeHandler($this->stubConnection([]), $em, $this->createStub(WeeklyRunnerGatewayInterface::class))
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

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            static function (string $class) use ($run, $entry): ?object {
                return match ($class) {
                    WeeklyRun::class => $run,
                    WeeklyEntry::class => $entry,
                    default => null,
                };
            }
        );
        $em->expects(self::never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('forbidden');

        $this->makeHandler($this->stubConnection([]), $em, $this->createStub(WeeklyRunnerGatewayInterface::class))
            ->execute('run-1', 'entry-1', 'user-1');
    }

    public function testInvokeThrowsWhenGameNotReady(): void
    {
        $run = $this->makeRun();
        $entry = $this->makeEntry();
        $template = $this->makeTemplate();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            static function (string $class) use ($run, $entry, $template): ?object {
                return match ($class) {
                    WeeklyRun::class => $run,
                    WeeklyEntry::class => $entry,
                    WeeklyTemplate::class => $template,
                    default => null,
                };
            }
        );
        $em->expects(self::never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('game_not_ready');

        // fetchAssociative returns false → no game row found
        $this->makeHandler($this->stubConnection([false]), $em, $this->createStub(WeeklyRunnerGatewayInterface::class))
            ->execute('run-1', 'entry-1', 'user-1');
    }

    public function testInvokeSubstitutesDisplayNameInYaml(): void
    {
        $run = $this->makeRun();
        $entry = $this->makeEntry();
        $template = $this->makeTemplate();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            static function (string $class) use ($run, $entry, $template): ?object {
                return match ($class) {
                    WeeklyRun::class => $run,
                    WeeklyEntry::class => $entry,
                    WeeklyTemplate::class => $template,
                    default => null,
                };
            }
        );
        $em->expects(self::once())->method('flush');

        /** @var list<string> $playerNames */
        $playerNames = [];
        $gateway = $this->createMock(WeeklyRunnerGatewayInterface::class);
        $gateway->expects(self::once())->method('launchEntry')
            ->willReturnCallback(static function (string $entryId, string $seed, string $key, string $url, string $playerName, string $yaml) use (&$playerNames): array {
                $playerNames[] = $playerName;

                return [
                    'externalSessionId' => 'sess-1',
                    'connectionInfo' => ['host' => 'runner.test', 'port' => 38281, 'password' => null],
                ];
            });

        $minio = $this->createStub(MinioStorageInterface::class);
        $minio->method('presignedUrl')->willReturn('http://minio.test/test.apworld');

        $result = $this->makeHandler(
            $this->stubConnection([
                ['apworld_storage_key' => 'apworlds/test.apworld'],
                ['display_name' => 'TestPlayer'],
            ]),
            $em,
            $gateway,
            $minio,
        )->execute('run-1', 'entry-1', 'user-1');

        self::assertSame(['TestPlayer'], $playerNames);
        self::assertSame('entry-1', $result['entryId']);
        self::assertSame('sess-1', $result['externalSessionId']);
        self::assertSame('runner.test', $result['connectionInfo']['host']);
    }

    public function testFlushFailureCallsTerminate(): void
    {
        $run = $this->makeRun();
        $entry = $this->makeEntry();
        $template = $this->makeTemplate();

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('find')->willReturnCallback(
            static function (string $class) use ($run, $entry, $template): ?object {
                return match ($class) {
                    WeeklyRun::class => $run,
                    WeeklyEntry::class => $entry,
                    WeeklyTemplate::class => $template,
                    default => null,
                };
            }
        );
        $em->method('flush')->willThrowException(new \RuntimeException('db error'));

        $gateway = $this->createMock(WeeklyRunnerGatewayInterface::class);
        $gateway->method('launchEntry')->willReturn([
            'externalSessionId' => 'sess-1',
            'connectionInfo' => ['host' => 'runner.test', 'port' => 38281, 'password' => null],
        ]);
        $gateway->expects(self::once())->method('terminate')->with('sess-1');

        $minio = $this->createStub(MinioStorageInterface::class);
        $minio->method('presignedUrl')->willReturn('http://minio.test/test.apworld');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('db error');

        $this->makeHandler(
            $this->stubConnection([
                ['apworld_storage_key' => 'apworlds/test.apworld'],
                ['display_name' => 'TestPlayer'],
            ]),
            $em,
            $gateway,
            $minio,
        )->execute('run-1', 'entry-1', 'user-1');
    }

    private function makeRun(): WeeklyRun
    {
        $now = new \DateTimeImmutable('2026-05-19T00:00:00+00:00');

        return new WeeklyRun(
            id: 'run-1',
            templateId: 'template-1',
            weekYear: 2026,
            weekNumber: 20,
            seed: 'archilan-weekly-2026-20',
            status: WeeklyRun::STATUS_ACTIVE,
            startedAt: $now,
            createdAt: $now,
        );
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

    private function makeTemplate(): WeeklyTemplate
    {
        $now = new \DateTimeImmutable('2026-05-19T00:00:00+00:00');

        return new WeeklyTemplate(
            id: 'template-1',
            gameId: 'game-1',
            yamlConfig: "name: ArchiLAN\ngame: Archipelago\n",
            name: 'Test Template',
            maxAttempts: null,
            isActive: true,
            createdAt: $now,
            updatedAt: $now,
        );
    }

    private function makeHandler(
        Connection $connection,
        EntityManagerInterface $em,
        WeeklyRunnerGatewayInterface $gateway,
        ?MinioStorageInterface $minio = null,
    ): LaunchWeeklyEntry {
        $minio ??= $this->createStub(MinioStorageInterface::class);

        return new LaunchWeeklyEntry(
            $connection,
            $em,
            $gateway,
            $minio,
            self::$clock,
            'apworlds',
            3600,
        );
    }

    /**
     * @param list<array<string, mixed>|false> $fetchAssociativeReturns consecutive return values for fetchAssociative()
     */
    private function stubConnection(array $fetchAssociativeReturns): Connection
    {
        $result = $this->createStub(Result::class);
        if ([] !== $fetchAssociativeReturns) {
            $result->method('fetchAssociative')->willReturnOnConsecutiveCalls(...$fetchAssociativeReturns);
        }

        $expr = $this->createStub(ExpressionBuilder::class);

        $qb = $this->createStub(QueryBuilder::class);
        $qb->method('select')->willReturn($qb);
        $qb->method('from')->willReturn($qb);
        $qb->method('where')->willReturn($qb);
        $qb->method('setParameter')->willReturn($qb);
        $qb->method('expr')->willReturn($expr);
        $qb->method('executeQuery')->willReturn($result);

        $connection = $this->createStub(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($qb);
        $connection->method('quoteSingleIdentifier')->willReturnArgument(0);

        return $connection;
    }
}
