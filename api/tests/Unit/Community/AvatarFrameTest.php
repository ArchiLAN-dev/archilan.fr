<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Domain\ValueObject\AvatarFrame;
use PHPUnit\Framework\TestCase;

/**
 * Story 30.46: the video frames are keys like any other; the frontend catalog maps each one to its assets.
 */
final class AvatarFrameTest extends TestCase
{
    public function testVideoFramesAreValidKeys(): void
    {
        foreach (['fire', 'electric', 'spectral_fire', 'lava', 'runes', 'cosmic', 'glitch'] as $key) {
            self::assertTrue(AvatarFrame::isValid($key), $key);
        }
    }

    public function testUnknownOrMisspelledKeysAreRejected(): void
    {
        self::assertFalse(AvatarFrame::isValid('bogus_frame'));
        self::assertFalse(AvatarFrame::isValid('spectral-fire'));
        self::assertFalse(AvatarFrame::isValid(''));
    }

    public function testLegendaryFramesAreForAdminsOnly(): void
    {
        foreach (AvatarFrame::LEGENDARY as $key) {
            self::assertTrue(AvatarFrame::isValid($key), $key);
            self::assertTrue(AvatarFrame::allowedFor($key, true), $key);
            self::assertFalse(AvatarFrame::allowedFor($key, false), $key);
        }
        self::assertTrue(AvatarFrame::allowedFor('gold', false));
        self::assertTrue(AvatarFrame::allowedFor('spectral', false));
    }

    public function testAProfileShowsALegendaryFrameOnlyWhileItsOwnerIsAdmin(): void
    {
        self::assertSame('fire', AvatarFrame::displayed('fire', true));
        self::assertNull(AvatarFrame::displayed('fire', false));
        self::assertSame('gold', AvatarFrame::displayed('gold', false));
        self::assertNull(AvatarFrame::displayed(null, true));
    }

    public function testKeysAreUniqueAndFitTheColumn(): void
    {
        self::assertSame(array_values(array_unique(AvatarFrame::ALL)), AvatarFrame::ALL);
        foreach (AvatarFrame::ALL as $key) {
            self::assertLessThanOrEqual(32, strlen($key), $key);
        }
    }
}
