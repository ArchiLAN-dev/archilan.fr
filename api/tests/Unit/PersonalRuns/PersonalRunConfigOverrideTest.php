<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Application\Service\PersonalRunConfigOverride;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\SessionConfig\Application\Command\ClearSessionConfigOverride;
use App\SessionConfig\Application\Command\SetSessionConfigOverride;
use App\SessionConfig\Application\Query\SessionConfigOverrideQuery;
use App\SessionConfig\Domain\Enum\SessionType;
use App\SessionConfig\Domain\Repository\SessionConfigOverrideRepositoryInterface;
use App\SessionConfig\Domain\Repository\SessionConfigProfileRepositoryInterface;
use App\SessionConfig\Domain\ValueObject\SessionConfig;
use App\SessionConfig\Domain\ValueObject\SessionConfigOverride;
use PHPUnit\Framework\TestCase;

final class PersonalRunConfigOverrideTest extends TestCase
{
    private function service(?Run $run, SessionConfigOverrideRepositoryInterface $overrides): PersonalRunConfigOverride
    {
        $runs = self::createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        $profiles = self::createStub(SessionConfigProfileRepositoryInterface::class);
        $profiles->method('get')->willReturn(SessionConfig::defaultsFor(SessionType::Private));

        return new PersonalRunConfigOverride(
            $runs,
            new SessionConfigOverrideQuery($overrides),
            new SetSessionConfigOverride($overrides),
            new ClearSessionConfigOverride($overrides),
            $profiles,
        );
    }

    public function testOwnerCanSetAndGet(): void
    {
        $run = Run::create('owner-1', 'My run', new \DateTimeImmutable());
        $overrides = $this->createMock(SessionConfigOverrideRepositoryInterface::class);
        $overrides->expects(self::once())->method('save')->with($run->getId());
        $overrides->method('find')->willReturn(null);

        $result = $this->service($run, $overrides)->set($run->getId(), 'owner-1', ['releaseMode' => 'goal']);

        self::assertTrue($result['found']);
        self::assertTrue($result['authorized']);
    }

    public function testNonOwnerIsNotAuthorized(): void
    {
        $run = Run::create('owner-1', 'My run', new \DateTimeImmutable());
        $overrides = $this->createMock(SessionConfigOverrideRepositoryInterface::class);
        $overrides->expects(self::never())->method('save');

        $result = $this->service($run, $overrides)->set($run->getId(), 'someone-else', ['releaseMode' => 'goal']);

        self::assertTrue($result['found']);
        self::assertFalse($result['authorized']);
    }

    public function testMissingRunIsNotFound(): void
    {
        $overrides = self::createStub(SessionConfigOverrideRepositoryInterface::class);

        $result = $this->service(null, $overrides)->get('missing', 'owner-1');

        self::assertFalse($result['found']);
        self::assertFalse($result['authorized']);
    }

    public function testOwnerCannotSetLockedAutoShutdown(): void
    {
        $run = Run::create('owner-1', 'My run', new \DateTimeImmutable());
        $overrides = $this->createMock(SessionConfigOverrideRepositoryInterface::class);
        $overrides->method('find')->willReturn(null);
        // autoShutdown is locked to the admin profile: it must be stripped, the sibling field saved.
        $overrides->expects(self::once())->method('save')->with(
            $run->getId(),
            self::callback(static fn (SessionConfigOverride $o): bool => null === $o->autoShutdown && null !== $o->releaseMode),
        );

        $this->service($run, $overrides)->set($run->getId(), 'owner-1', ['autoShutdown' => 600, 'releaseMode' => 'goal']);
    }

    public function testOwnerOnlyLockedFieldClearsTheOverride(): void
    {
        $run = Run::create('owner-1', 'My run', new \DateTimeImmutable());
        $overrides = $this->createMock(SessionConfigOverrideRepositoryInterface::class);
        $overrides->method('find')->willReturn(null);
        // An override carrying only the locked field becomes empty after stripping → delete, never save.
        $overrides->expects(self::never())->method('save');
        $overrides->expects(self::once())->method('delete')->with($run->getId());

        $this->service($run, $overrides)->set($run->getId(), 'owner-1', ['autoShutdown' => 600]);
    }

    public function testOwnerSetInvalidThrows(): void
    {
        $run = Run::create('owner-1', 'My run', new \DateTimeImmutable());
        $overrides = self::createStub(SessionConfigOverrideRepositoryInterface::class);

        $this->expectException(\DomainException::class);
        $this->service($run, $overrides)->set($run->getId(), 'owner-1', ['spoiler' => 9]);
    }

    public function testSetIsBlockedOnTerminalRun(): void
    {
        // A finished run is read-only: the override must not be saved (#338).
        $run = $this->completedRun();
        $overrides = $this->createMock(SessionConfigOverrideRepositoryInterface::class);
        $overrides->expects(self::never())->method('save');

        $result = $this->service($run, $overrides)->set($run->getId(), 'owner-1', ['releaseMode' => 'goal']);

        self::assertTrue($result['found']);
        self::assertTrue($result['authorized']);
        self::assertTrue($result['blocked'] ?? false);
    }

    public function testClearIsBlockedOnTerminalRun(): void
    {
        $run = $this->completedRun();
        $overrides = $this->createMock(SessionConfigOverrideRepositoryInterface::class);
        $overrides->expects(self::never())->method('delete');

        $result = $this->service($run, $overrides)->clear($run->getId(), 'owner-1');

        self::assertTrue($result['blocked'] ?? false);
    }

    private function completedRun(): Run
    {
        $now = new \DateTimeImmutable('2026-06-20T10:00:00+00:00');
        $run = Run::create('owner-1', 'My run', $now);
        $run->attachSession('sess-1');
        $run->start($now);
        $run->markRunning('runner.example.com', 38281, $now, 'deadbeef12345678');
        $run->complete($now);

        return $run;
    }
}
