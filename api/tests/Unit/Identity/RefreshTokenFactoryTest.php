<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Application\RefreshTokenFactory;
use PHPUnit\Framework\TestCase;

final class RefreshTokenFactoryTest extends TestCase
{
    private RefreshTokenFactory $factory;

    protected function setUp(): void
    {
        $this->factory = new RefreshTokenFactory();
    }

    public function testIssuedRawTokenIsBase64UrlEncoded(): void
    {
        $now = new \DateTimeImmutable();
        ['rawToken' => $rawToken] = $this->factory->issue('user-1', $now);

        self::assertMatchesRegularExpression('/^[A-Za-z0-9\-_]+=*$/', $rawToken);
        self::assertGreaterThanOrEqual(80, strlen($rawToken));
    }

    public function testEntityHashMatchesRawToken(): void
    {
        $now = new \DateTimeImmutable();
        ['rawToken' => $rawToken, 'entity' => $entity] = $this->factory->issue('user-1', $now);

        self::assertSame(hash('sha256', $rawToken), $entity->getTokenHash());
    }

    public function testTwoCallsProduceDifferentTokens(): void
    {
        $now = new \DateTimeImmutable();
        ['rawToken' => $token1] = $this->factory->issue('user-1', $now);
        ['rawToken' => $token2] = $this->factory->issue('user-1', $now);

        self::assertNotSame($token1, $token2);
    }

    public function testExpiresAtIsThirtyDaysFromNow(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00 UTC');
        ['entity' => $entity] = $this->factory->issue('user-1', $now);

        $expected = $now->modify(sprintf('+%d days', RefreshTokenFactory::TOKEN_TTL_LONG_DAYS));
        self::assertSame($expected->getTimestamp(), $entity->getExpiresAt()->getTimestamp());
    }

    public function testRememberMeFalseGivesOneDayTtl(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00 UTC');
        ['entity' => $entity] = $this->factory->issue('user-1', $now, null, false);

        $expected = $now->modify(sprintf('+%d days', RefreshTokenFactory::TOKEN_TTL_SHORT_DAYS));
        self::assertSame($expected->getTimestamp(), $entity->getExpiresAt()->getTimestamp());
        self::assertFalse($entity->isRememberMe());
    }

    public function testRememberMeTrueGivesThirtyDayTtl(): void
    {
        $now = new \DateTimeImmutable('2026-01-01 00:00:00 UTC');
        ['entity' => $entity] = $this->factory->issue('user-1', $now, null, true);

        $expected = $now->modify(sprintf('+%d days', RefreshTokenFactory::TOKEN_TTL_LONG_DAYS));
        self::assertSame($expected->getTimestamp(), $entity->getExpiresAt()->getTimestamp());
        self::assertTrue($entity->isRememberMe());
    }

    public function testUserAgentIsPassedToEntity(): void
    {
        $now = new \DateTimeImmutable();
        ['entity' => $entity] = $this->factory->issue('user-1', $now, 'Mozilla/5.0');

        self::assertSame('Mozilla/5.0', $entity->getUserAgent());
    }
}
