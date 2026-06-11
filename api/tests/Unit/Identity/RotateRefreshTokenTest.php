<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\AuthenticateUser;
use App\Identity\Application\RefreshTokenFactory;
use App\Identity\Application\RotateRefreshToken;
use App\Identity\Domain\RefreshToken;
use App\Identity\Domain\RefreshTokenRepositoryInterface;
use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class RotateRefreshTokenTest extends TestCase
{
    private const FAMILY = 'fam00000000000000000000000000001';

    public function testNormalRotationKeepsFamilyAndMarksParent(): void
    {
        $existing = $this->validToken();
        $repo = $this->createMock(RefreshTokenRepositoryInterface::class);
        $repo->method('findByTokenHash')->willReturn($existing);
        $repo->expects(self::never())->method('revokeFamily');
        $persisted = null;
        $repo->expects(self::once())->method('persist')->with(self::callback(
            function (RefreshToken $t) use (&$persisted): bool { $persisted = $t; return true; },
        ));

        $result = $this->rotator($repo)->rotate('raw', $this->now(), 'UA', $this->req());

        self::assertSame('rotated', $result->outcome);
        self::assertInstanceOf(RefreshToken::class, $persisted);
        self::assertSame(self::FAMILY, $persisted->getFamilyId());
        self::assertTrue($existing->isRevoked());
        self::assertNotNull($existing->getReplacedByTokenHash());
    }

    public function testGenuineReuseRevokesOnlyTheFamily(): void
    {
        $existing = $this->validToken();
        $existing->revoke($this->now()->modify('-1 hour')); // revoked, no successor -> genuine reuse

        $repo = $this->createMock(RefreshTokenRepositoryInterface::class);
        $repo->method('findByTokenHash')->willReturn($existing);
        $repo->expects(self::once())->method('revokeFamily')->with(self::FAMILY);
        $repo->expects(self::never())->method('persist');

        $result = $this->rotator($repo)->rotate('raw', $this->now(), 'UA', $this->req());

        self::assertSame('reuse_detected', $result->outcome);
    }

    public function testGraceRetryReissuesInSameFamilyWithoutRevoking(): void
    {
        $existing = $this->validToken();
        $existing->markRotated('child-hash', $this->now()); // rotated just now -> within grace

        $repo = $this->createMock(RefreshTokenRepositoryInterface::class);
        $repo->method('findByTokenHash')->willReturn($existing);
        $repo->expects(self::never())->method('revokeFamily');
        $persisted = null;
        $repo->expects(self::once())->method('persist')->with(self::callback(
            function (RefreshToken $t) use (&$persisted): bool { $persisted = $t; return true; },
        ));

        $result = $this->rotator($repo)->rotate('raw', $this->now(), 'UA', $this->req());

        self::assertSame('rotated', $result->outcome);
        self::assertInstanceOf(RefreshToken::class, $persisted);
        self::assertSame(self::FAMILY, $persisted->getFamilyId());
    }

    public function testRotatedLongAgoIsTreatedAsReuse(): void
    {
        $existing = $this->validToken();
        $existing->markRotated('child-hash', $this->now()->modify('-10 minutes')); // outside grace

        $repo = $this->createMock(RefreshTokenRepositoryInterface::class);
        $repo->method('findByTokenHash')->willReturn($existing);
        $repo->expects(self::once())->method('revokeFamily')->with(self::FAMILY);

        $result = $this->rotator($repo)->rotate('raw', $this->now(), 'UA', $this->req());

        self::assertSame('reuse_detected', $result->outcome);
    }

    public function testExpiredTokenIsInvalid(): void
    {
        $expired = RefreshToken::issue('user-1', 'raw', $this->now()->modify('-1 day'), $this->now()->modify('-31 days'), 'UA', true, self::FAMILY);
        $repo = $this->createMock(RefreshTokenRepositoryInterface::class);
        $repo->method('findByTokenHash')->willReturn($expired);
        $repo->expects(self::never())->method('revokeFamily');
        $repo->expects(self::never())->method('persist');

        self::assertSame('invalid', $this->rotator($repo)->rotate('raw', $this->now(), 'UA', $this->req())->outcome);
    }

    public function testUnknownTokenIsInvalid(): void
    {
        $repo = $this->createMock(RefreshTokenRepositoryInterface::class);
        $repo->method('findByTokenHash')->willReturn(null);

        self::assertSame('invalid', $this->rotator($repo)->rotate('raw', $this->now(), 'UA', $this->req())->outcome);
    }

    private function validToken(): RefreshToken
    {
        return RefreshToken::issue('user-1', 'raw', $this->now()->modify('+30 days'), $this->now()->modify('-1 minute'), 'UA', true, self::FAMILY);
    }

    private function rotator(RefreshTokenRepositoryInterface $repo): RotateRefreshToken
    {
        $userRepo = $this->createStub(UserRepositoryInterface::class);
        $userRepo->method('findById')->willReturn($this->user());

        $auth = new AuthenticateUser($userRepo, $this->createStub(UserPasswordHasherInterface::class));

        return new RotateRefreshToken($repo, new RefreshTokenFactory(), $auth, $this->createStub(LoggerInterface::class));
    }

    private function user(): User
    {
        $t = $this->now();

        return new User('user-1', 'u@example.com', 'u@example.com', 'U', 'hash', ['ROLE_USER'], $t, $t, $t);
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-06-11T12:00:00+00:00');
    }

    private function req(): Request
    {
        return Request::create('/api/v1/auth/refresh', 'POST');
    }
}
