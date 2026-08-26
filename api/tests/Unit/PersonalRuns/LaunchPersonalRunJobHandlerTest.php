<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\PersonalRuns\Application\Handler\LaunchPersonalRunJobHandler;
use App\PersonalRuns\Application\Message\LaunchPersonalRunJob;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Application\Port\PersonalRunAdvancerInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use App\Sessions\Application\Support\SlotNameGenerator;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\Sessions\Domain\Repository\SlotCoPlayerRepositoryInterface;
use App\Sessions\Infrastructure\Double\NullRunnerGateway;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\MockClock;

final class LaunchPersonalRunJobHandlerTest extends TestCase
{
    public function testPersonalRunNotFoundLogsErrorAndReturns(): void
    {
        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn(null);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('personal_run.launch.not_found', self::arrayHasKey('runId'));

        $this->makeHandler(runs: $runs, logger: $logger)(new LaunchPersonalRunJob('run-missing'));
    }

    public function testNoParticipantsLogsErrorAndReturns(): void
    {
        $now = new \DateTimeImmutable();
        $run = Run::create('owner-1', 'Test Run', $now);

        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        $participants = self::createStub(RunParticipantRepositoryInterface::class);
        $participants->method('findByRunId')->willReturn([]);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('error')
            ->with('personal_run.launch.no_slots', self::arrayHasKey('runId'));

        $this->makeHandler(runs: $runs, participants: $participants, logger: $logger)(new LaunchPersonalRunJob($run->getId()));
    }

    public function testLiteralCustomNameReachesConfigureSlots(): void
    {
        NullRunnerGateway::reset();
        $now = new \DateTimeImmutable();

        $user = User::register('p@x.test', 'p@x.test', 'hash', $now, 'masterkafei', 'masterkafei');
        $game = Game::create('Hollow Knight', 'hollow-knight', 'desc', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, $now);

        $run = Run::create($user->getId(), 'My run', $now);

        $participant = RunParticipant::create($run->getId(), $user->getId(), $now);
        $participant->replaceSlots([[
            'slotId' => 'slot-1',
            'gameId' => $game->getId(),
            'playerYaml' => "name: MasterKafey\ngame: Hollow Knight\n",
            'apworldHash' => 'deadbeef',
        ]]);

        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);
        $participants = self::createStub(RunParticipantRepositoryInterface::class);
        $participants->method('findByRunId')->willReturn([$participant]);
        $users = self::createStub(UserRepositoryInterface::class);
        $users->method('findByIds')->willReturn([$user]);
        $games = self::createStub(GameRepositoryInterface::class);
        $games->method('findByIds')->willReturn([$game]);

        $handler = new LaunchPersonalRunJobHandler(
            $runs,
            $participants,
            $users,
            $games,
            self::createStub(SessionRepositoryInterface::class),
            self::createStub(SessionSlotRepositoryInterface::class),
            new SlotNameGenerator(),
            new NullRunnerGateway(),
            self::createStub(PersonalRunAdvancerInterface::class),
            self::createStub(LoggerInterface::class),
            new MockClock(),
            self::createStub(SlotCoPlayerRepositoryInterface::class),
        );

        $handler(new LaunchPersonalRunJob($run->getId()));

        self::assertNotNull(NullRunnerGateway::$lastConfigureSlots);
        self::assertSame('MasterKafey', NullRunnerGateway::$lastConfigureSlots[0]['slotName']);
    }

    private function makeHandler(
        ?RunRepositoryInterface $runs = null,
        ?RunParticipantRepositoryInterface $participants = null,
        ?RunnerGatewayInterface $runnerGateway = null,
        ?LoggerInterface $logger = null,
    ): LaunchPersonalRunJobHandler {
        return new LaunchPersonalRunJobHandler(
            $runs ?? self::createStub(RunRepositoryInterface::class),
            $participants ?? self::createStub(RunParticipantRepositoryInterface::class),
            self::createStub(UserRepositoryInterface::class),
            self::createStub(GameRepositoryInterface::class),
            self::createStub(SessionRepositoryInterface::class),
            self::createStub(SessionSlotRepositoryInterface::class),
            new SlotNameGenerator(),
            $runnerGateway ?? self::createStub(RunnerGatewayInterface::class),
            self::createStub(PersonalRunAdvancerInterface::class),
            $logger ?? self::createStub(LoggerInterface::class),
            new MockClock(),
            self::createStub(SlotCoPlayerRepositoryInterface::class),
        );
    }
}
