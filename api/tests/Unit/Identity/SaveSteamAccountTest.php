<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\Command\SaveSteamAccount;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SaveSteamAccountTest extends TestCase
{
    public function testSavesParseableProfile(): void
    {
        $user = $this->user();

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findById')->willReturn($user);
        $repo->expects(self::once())->method('save')->with($user);

        $service = new SaveSteamAccount($repo, self::createStub(LoggerInterface::class));

        $service->save($user->getId(), '76561197960287930');

        self::assertSame('76561197960287930', $user->getSteamProfile());
    }

    public function testRejectsUnparseableProfileWithoutSaving(): void
    {
        $user = $this->user();

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findById')->willReturn($user);
        $repo->expects(self::never())->method('save');

        $service = new SaveSteamAccount($repo, self::createStub(LoggerInterface::class));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Profil Steam non reconnu.');
        $service->save($user->getId(), 'bad profile !!');
    }

    public function testReturnsNotFoundWhenUserMissing(): void
    {
        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findById')->willReturn(null);
        $repo->expects(self::never())->method('save');

        $service = new SaveSteamAccount($repo, self::createStub(LoggerInterface::class));

        $this->expectException(NotFoundException::class);
        $service->save('missing', '76561197960287930');
    }

    public function testRemoveClearsTheProfile(): void
    {
        $user = $this->user();
        $user->updateSteamProfile('gaben');

        $repo = $this->createMock(UserRepositoryInterface::class);
        $repo->method('findById')->willReturn($user);
        $repo->expects(self::once())->method('save')->with($user);

        $service = new SaveSteamAccount($repo, self::createStub(LoggerInterface::class));

        $service->remove($user->getId());

        self::assertNull($user->getSteamProfile());
    }

    private function user(): User
    {
        return User::register(
            'player@example.com',
            'player@example.com',
            'hash',
            new \DateTimeImmutable('2026-06-15T10:00:00+00:00'),
        );
    }
}
