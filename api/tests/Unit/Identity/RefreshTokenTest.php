<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Domain\RefreshToken;
use PHPUnit\Framework\TestCase;

final class RefreshTokenTest extends TestCase
{
    public function testIssueStoresHashNotRawToken(): void
    {
        $rawToken = 'my-super-secret-raw-token-value';
        $now = new \DateTimeImmutable('2026-01-01 10:00:00');
        $expiresAt = $now->modify('+30 days');

        $token = RefreshToken::issue('user-id-1', $rawToken, $expiresAt, $now);

        self::assertSame(hash('sha256', $rawToken), $token->getTokenHash());
        self::assertNotSame($rawToken, $token->getTokenHash());
    }

    public function testNewTokenIsNotRevoked(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 10:00:00');
        $token = RefreshToken::issue('user-id-1', 'raw-token', $now->modify('+30 days'), $now);

        self::assertFalse($token->isRevoked());
        self::assertNull($token->getRevokedAt());
    }

    public function testNewTokenIsNotExpired(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 10:00:00');
        $token = RefreshToken::issue('user-id-1', 'raw-token', $now->modify('+30 days'), $now);

        self::assertFalse($token->isExpired($now));
    }

    public function testExpiredTokenIsDetected(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 10:00:00');
        $expiresAt = $now->modify('-1 second');
        $token = RefreshToken::issue('user-id-1', 'raw-token', $expiresAt, $now->modify('-1 day'));

        self::assertTrue($token->isExpired($now));
    }

    public function testRevokeMarksToken(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 10:00:00');
        $token = RefreshToken::issue('user-id-1', 'raw-token', $now->modify('+30 days'), $now);

        $revokedAt = $now->modify('+1 hour');
        $token->revoke($revokedAt);

        self::assertTrue($token->isRevoked());
        self::assertSame($revokedAt, $token->getRevokedAt());
    }

    public function testRevokeTwiceKeepsFirstTimestamp(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 10:00:00');
        $token = RefreshToken::issue('user-id-1', 'raw-token', $now->modify('+30 days'), $now);

        $first = $now->modify('+1 hour');
        $second = $now->modify('+2 hours');
        $token->revoke($first);
        $token->revoke($second);

        self::assertSame($first, $token->getRevokedAt());
    }

    public function testUserAgentIsTruncatedTo255Characters(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 10:00:00');
        $longUserAgent = str_repeat('x', 300);

        $token = RefreshToken::issue('user-id-1', 'raw-token', $now->modify('+30 days'), $now, $longUserAgent);

        self::assertSame(str_repeat('x', 255), $token->getUserAgent());
    }
}
