<?php

declare(strict_types=1);

namespace App\Tests\Unit\WeeklyRuns;

use App\WeeklyRuns\Application\Handler\GenerateWeeklyRunsMessageHandler;
use App\WeeklyRuns\Application\Message\GenerateWeeklyRunsMessage;
use App\WeeklyRuns\Domain\WeeklyRun;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\Expression\ExpressionBuilder;
use Doctrine\DBAL\Query\QueryBuilder;
use Doctrine\DBAL\Result;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

final class GenerateWeeklyRunsMessageHandlerTest extends TestCase
{
    private static \DateTimeImmutable $defaultNow;

    public static function setUpBeforeClass(): void
    {
        // A stable Monday in UTC for general tests.
        self::$defaultNow = new \DateTimeImmutable('2026-05-18T00:00:00', new \DateTimeZone('UTC'));
    }

    public function testInvokeSkipsTemplateWhenRunAlreadyExistsForCurrentWeek(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $this->makeHandler($this->createConnectionReturning(['template-1'], 1), $em)
            ->__invoke(new GenerateWeeklyRunsMessage());
    }

    public function testInvokeCreatesWeeklyRunWhenNoneExistsForCurrentWeek(): void
    {
        /** @var WeeklyRun|null $persisted */
        $persisted = null;

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->willReturnCallback(static function (object $run) use (&$persisted): void {
            $persisted = $run instanceof WeeklyRun ? $run : null;
        });
        $em->expects(self::once())->method('flush');

        $this->makeHandler($this->createConnectionReturning(['template-1'], 0), $em)
            ->__invoke(new GenerateWeeklyRunsMessage());

        self::assertInstanceOf(WeeklyRun::class, $persisted);
        self::assertSame(WeeklyRun::STATUS_ACTIVE, $persisted->getStatus());
        self::assertSame('template-1', $persisted->getTemplateId());
    }

    public function testInvokeSeedIsRandomPositiveInteger(): void
    {
        /** @var WeeklyRun|null $persisted */
        $persisted = null;

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $run) use (&$persisted): void {
            $persisted = $run instanceof WeeklyRun ? $run : null;
        });

        $this->makeHandler($this->createConnectionReturning(['template-1'], 0), $em)
            ->__invoke(new GenerateWeeklyRunsMessage());

        self::assertInstanceOf(WeeklyRun::class, $persisted);
        self::assertMatchesRegularExpression('/^\d+$/', $persisted->getSeed());
        self::assertGreaterThan(0, (int) $persisted->getSeed());
    }

    public function testInvokeUsesIsoYearNotCalendarYearAtYearBoundary(): void
    {
        // 2027-01-01 is calendar year 2027 but ISO year 2026 (ISO week 53).
        // format('Y') would give 2027, format('o') gives 2026 - the handler must use 'o'.
        $boundaryDate = new \DateTimeImmutable('2027-01-01T00:00:00', new \DateTimeZone('UTC'));
        $expectedYear = (int) $boundaryDate->format('o');
        $expectedWeek = (int) $boundaryDate->format('W');

        /** @var WeeklyRun|null $persisted */
        $persisted = null;

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $run) use (&$persisted): void {
            $persisted = $run instanceof WeeklyRun ? $run : null;
        });

        $this->makeHandler($this->createConnectionReturning(['template-1'], 0), $em, $boundaryDate)
            ->__invoke(new GenerateWeeklyRunsMessage());

        self::assertInstanceOf(WeeklyRun::class, $persisted);
        self::assertSame($expectedYear, $persisted->getWeekYear());
        self::assertSame($expectedWeek, $persisted->getWeekNumber());
        // Specifically: ISO year must NOT equal calendar year for this boundary date.
        self::assertNotSame((int) $boundaryDate->format('Y'), $persisted->getWeekYear());
    }

    public function testInvokeSkipsNonStringTemplateIds(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $this->makeHandler($this->createConnectionReturning([42, null], 0), $em)
            ->__invoke(new GenerateWeeklyRunsMessage());
    }

    private function makeHandler(
        Connection $connection,
        EntityManagerInterface $em,
        ?\DateTimeImmutable $now = null,
    ): GenerateWeeklyRunsMessageHandler {
        return new GenerateWeeklyRunsMessageHandler(
            $connection,
            $em,
            new MockClock($now ?? self::$defaultNow),
        );
    }

    /**
     * @param list<mixed> $templateIds
     */
    private function createConnectionReturning(array $templateIds, int|false $count): Connection
    {
        $result = $this->createStub(Result::class);
        $result->method('fetchFirstColumn')->willReturn($templateIds);
        $result->method('fetchOne')->willReturn($count);

        $expr = $this->createStub(ExpressionBuilder::class);

        $qb = $this->createStub(QueryBuilder::class);
        $qb->method('select')->willReturn($qb);
        $qb->method('from')->willReturn($qb);
        $qb->method('where')->willReturn($qb);
        $qb->method('andWhere')->willReturn($qb);
        $qb->method('setParameter')->willReturn($qb);
        $qb->method('expr')->willReturn($expr);
        $qb->method('executeQuery')->willReturn($result);

        $connection = $this->createStub(Connection::class);
        $connection->method('createQueryBuilder')->willReturn($qb);

        return $connection;
    }
}
