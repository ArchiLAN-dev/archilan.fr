<?php

declare(strict_types=1);

namespace App\Tests\Unit\Trackers;

use App\Trackers\Domain\Enum\TrackerPackSourceKind;
use App\Trackers\Domain\ValueObject\TrackerPackSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrackerPackSourceTest extends TestCase
{
    public function testReleasePageGivesItsTag(): void
    {
        $source = TrackerPackSource::parse(' https://github.com/G4M3RL1F3/Majoras-Mask-AP-PopTracker-Pack/releases/tag/v0.9.5.3 ');

        self::assertSame(TrackerPackSourceKind::Release, $source->kind);
        self::assertSame('G4M3RL1F3', $source->owner);
        self::assertSame('Majoras-Mask-AP-PopTracker-Pack', $source->repo);
        self::assertSame('v0.9.5.3', $source->tag);
        self::assertSame('https://github.com/G4M3RL1F3/Majoras-Mask-AP-PopTracker-Pack', $source->repositoryUrl());
    }

    /** @return iterable<string, array{string}> */
    public static function repositoryLinks(): iterable
    {
        yield 'repository' => ['https://github.com/owner/repo'];
        yield 'trailing slash' => ['https://github.com/owner/repo/'];
        yield 'git suffix' => ['https://github.com/owner/repo.git'];
        yield 'releases' => ['https://github.com/owner/repo/releases'];
        yield 'latest' => ['https://github.com/owner/repo/releases/latest'];
    }

    #[DataProvider('repositoryLinks')]
    public function testRepositoryLinksGiveTheLatestRelease(string $url): void
    {
        $source = TrackerPackSource::parse($url);

        self::assertSame(TrackerPackSourceKind::LatestRelease, $source->kind);
        self::assertSame('repo', $source->repo);
        self::assertNull($source->tag);
    }

    public function testTagArchiveIsAZipWithItsTag(): void
    {
        $source = TrackerPackSource::parse('https://github.com/owner/repo/archive/refs/tags/v1.2.zip');

        self::assertSame(TrackerPackSourceKind::Zip, $source->kind);
        self::assertSame('v1.2', $source->tag);
        self::assertSame('https://github.com/owner/repo/archive/refs/tags/v1.2.zip', $source->zipUrl);
    }

    public function testReleaseAssetIsAZip(): void
    {
        $source = TrackerPackSource::parse('https://github.com/owner/repo/releases/download/v2/My%20Pack.zip');

        self::assertSame(TrackerPackSourceKind::Zip, $source->kind);
        self::assertSame('v2', $source->tag);
        self::assertSame('https://github.com/owner/repo/releases/download/v2/My%20Pack.zip', $source->zipUrl);
    }

    /** @return iterable<string, array{string}> */
    public static function refusedLinks(): iterable
    {
        yield 'other host' => ['https://gitlab.com/owner/repo'];
        yield 'look-alike host' => ['https://github.com.evil.test/owner/repo'];
        yield 'plain http' => ['http://github.com/owner/repo'];
        yield 'credentials' => ['https://user:pass@github.com/owner/repo'];
        yield 'port' => ['https://github.com:8443/owner/repo'];
        yield 'owner only' => ['https://github.com/owner'];
        yield 'tree page' => ['https://github.com/owner/repo/tree/main'];
        yield 'archive not zip' => ['https://github.com/owner/repo/archive/refs/tags/v1.tar.gz'];
        yield 'asset not zip' => ['https://github.com/owner/repo/releases/download/v1/pack.exe'];
        yield 'dotted tag' => ['https://github.com/owner/repo/releases/tag/..'];
        yield 'empty' => [''];
        yield 'not a url' => ['majora'];
    }

    #[DataProvider('refusedLinks')]
    public function testOtherLinksAreRefused(string $url): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('tracker_pack_url_invalid');

        TrackerPackSource::parse($url);
    }
}
