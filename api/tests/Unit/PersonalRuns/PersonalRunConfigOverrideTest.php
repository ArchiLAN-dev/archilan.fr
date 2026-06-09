<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Application\PersonalRunConfigOverride;
use App\PersonalRuns\Domain\Run;
use App\PersonalRuns\Domain\RunRepositoryInterface;
use App\SessionConfig\Application\ClearSessionConfigOverride;
use App\SessionConfig\Application\SessionConfigOverrideQuery;
use App\SessionConfig\Application\SetSessionConfigOverride;
use App\SessionConfig\Domain\SessionConfig;
use App\SessionConfig\Domain\SessionConfigOverrideRepositoryInterface;
use App\SessionConfig\Domain\SessionConfigProfileRepositoryInterface;
use App\SessionConfig\Domain\SessionType;
use PHPUnit\Framework\TestCase;

final class PersonalRunConfigOverrideTest extends TestCase
{
    private function service(?Run $run, SessionConfigOverrideRepositoryInterface $overrides): PersonalRunConfigOverride
    {
        $runs = $this->createStub(RunRepositoryInterface::class);
        $runs->method('findById')->willReturn($run);

        $profiles = $this->createStub(SessionConfigProfileRepositoryInterface::class);
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
        $overrides = $this->createStub(SessionConfigOverrideRepositoryInterface::class);

        $result = $this->service(null, $overrides)->get('missing', 'owner-1');

        self::assertFalse($result['found']);
        self::assertFalse($result['authorized']);
    }

    public function testOwnerSetInvalidThrows(): void
    {
        $run = Run::create('owner-1', 'My run', new \DateTimeImmutable());
        $overrides = $this->createStub(SessionConfigOverrideRepositoryInterface::class);

        $this->expectException(\DomainException::class);
        $this->service($run, $overrides)->set($run->getId(), 'owner-1', ['spoiler' => 9]);
    }
}
