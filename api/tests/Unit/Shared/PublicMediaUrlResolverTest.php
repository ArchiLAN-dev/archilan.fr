<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Application\Support\PublicMediaUrlResolver;
use App\Shared\Infrastructure\Double\NullMinioStorage;
use PHPUnit\Framework\TestCase;

final class PublicMediaUrlResolverTest extends TestCase
{
    protected function setUp(): void
    {
        NullMinioStorage::reset();
    }

    public function testResolveJoinsBaseUrlAndKeyWhenConfigured(): void
    {
        $resolver = new PublicMediaUrlResolver(new NullMinioStorage(), 'media-public', 'https://media.archilan.fr', 3600);

        self::assertTrue($resolver->isStable());
        self::assertSame('https://media.archilan.fr/events/e1/cover.webp', $resolver->resolve('events/e1/cover.webp'));
    }

    public function testResolveTrimsRedundantSlashes(): void
    {
        $resolver = new PublicMediaUrlResolver(new NullMinioStorage(), 'media-public', 'https://media.archilan.fr/', 3600);

        self::assertSame('https://media.archilan.fr/posts/p1/cover.webp', $resolver->resolve('/posts/p1/cover.webp'));
    }

    public function testFallsBackToPresignedUrlOnThePublicBucketWhenNotConfigured(): void
    {
        $resolver = new PublicMediaUrlResolver(new NullMinioStorage(), 'media-public', '', 3600);

        self::assertFalse($resolver->isStable());
        $url = $resolver->resolve('events/e1/cover.webp');
        self::assertStringContainsString('media-public/events/e1/cover.webp', $url);
        self::assertStringContainsString('X-Amz-Signature', $url);
    }

    public function testBucketReturnsTheConfiguredPublicBucket(): void
    {
        $resolver = new PublicMediaUrlResolver(new NullMinioStorage(), 'media-public', '', 3600);

        self::assertSame('media-public', $resolver->bucket());
    }
}
