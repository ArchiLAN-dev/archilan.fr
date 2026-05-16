<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Application\Handler\LaunchPersonalRunJobHandler;
use App\PersonalRuns\Application\Message\LaunchPersonalRunJob;
use App\PersonalRuns\Domain\Run;
use App\Sessions\Application\SlotNameGenerator;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final class LaunchPersonalRunJobHandlerTest extends TestCase
{
    private EntityManagerInterface&Stub $entityManager;

    protected function setUp(): void
    {
        $this->entityManager = $this->createStub(EntityManagerInterface::class);
    }

    public function testPersonalRunNotFoundLogsErrorAndReturns(): void
    {
        $this->entityManager->method('find')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('personal_run.launch.not_found', $this->arrayHasKey('runId'));

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $this->makeHandler(messageBus: $messageBus, logger: $logger)(new LaunchPersonalRunJob('run-missing'));
    }

    public function testNoParticipantsLogsErrorAndReturns(): void
    {
        $now = new \DateTimeImmutable();
        $run = Run::create('owner-1', 'Test Run', $now);

        $this->entityManager->method('find')->willReturn($run);

        $query = $this->createStub(Query::class);
        $query->method('getResult')->willReturn([]);

        $qb = $this->createStub(QueryBuilder::class);
        $qb->method('select')->willReturnSelf();
        $qb->method('from')->willReturnSelf();
        $qb->method('where')->willReturnSelf();
        $qb->method('setParameter')->willReturnSelf();
        $qb->method('getQuery')->willReturn($query);

        $this->entityManager->method('createQueryBuilder')->willReturn($qb);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with('personal_run.launch.no_slots', $this->arrayHasKey('runId'));

        $messageBus = $this->createMock(MessageBusInterface::class);
        $messageBus->expects($this->never())->method('dispatch');

        $this->makeHandler(messageBus: $messageBus, logger: $logger)(new LaunchPersonalRunJob($run->getId()));
    }

    private function makeHandler(
        ?MessageBusInterface $messageBus = null,
        ?LoggerInterface $logger = null,
    ): LaunchPersonalRunJobHandler {
        return new LaunchPersonalRunJobHandler(
            $this->entityManager,
            $messageBus ?? $this->createStub(MessageBusInterface::class),
            new SlotNameGenerator(),
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }
}
