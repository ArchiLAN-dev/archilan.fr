<?php

declare(strict_types=1);

namespace App\Tests\Unit\Trackers;

use App\Shared\Application\Exception\ValidationException;
use App\Tests\Fixtures\Trackers\TrackerPackZip;
use App\Trackers\Infrastructure\Adapter\ZipTrackerPackArchiveInspector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ZipTrackerPackArchiveInspectorTest extends TestCase
{
    public function testSourceArchiveRootIsItsFolder(): void
    {
        $inspected = new ZipTrackerPackArchiveInspector()->inspect(TrackerPackZip::sourceArchive());

        self::assertSame('Majoras-Mask-AP-PopTracker-Pack-0.9.5.3', $inspected->rootPath);
        self::assertSame(TrackerPackZip::MANIFEST, $inspected->manifest);
    }

    public function testManifestAtTheRootGivesAnEmptyRoot(): void
    {
        $inspected = new ZipTrackerPackArchiveInspector()->inspect(TrackerPackZip::build([
            'manifest.json' => '{}',
            'sub/manifest.json' => '{"other": true}',
        ]));

        self::assertSame('', $inspected->rootPath);
        self::assertSame('{}', $inspected->manifest);
    }

    public function testManifestTooDeepIsMissing(): void
    {
        $this->assertRefused('tracker_pack_manifest_missing', TrackerPackZip::build(['a/b/manifest.json' => '{}']));
    }

    public function testZipWithoutManifestIsRefused(): void
    {
        $this->assertRefused('tracker_pack_manifest_missing', TrackerPackZip::build(['readme.md' => 'hi']));
    }

    public function testNotAZipIsRefused(): void
    {
        $this->assertRefused('tracker_pack_archive_invalid', 'not a zip at all');
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeNames(): iterable
    {
        yield 'parent folder' => ['pack/../../etc/passwd'];
        yield 'absolute' => ['/etc/passwd'];
        yield 'backslash' => ['pack\\evil.lua'];
        yield 'drive letter' => ['C:/evil.lua'];
    }

    #[DataProvider('unsafeNames')]
    public function testUnsafePathIsRefused(string $name): void
    {
        $this->assertRefused('tracker_pack_unsafe_path', TrackerPackZip::build(['manifest.json' => '{}', $name => 'x']));
    }

    public function testTooManyFilesIsRefused(): void
    {
        $entries = ['manifest.json' => '{}'];
        for ($i = 0; $i < 5000; ++$i) {
            $entries['f'.$i] = '';
        }

        $this->assertRefused('tracker_pack_too_large', TrackerPackZip::build($entries));
    }

    public function testDeclaredUnpackedSizeIsCapped(): void
    {
        // Highly compressible: a few hundred kilobytes once zipped, over the 200 MB cap once unpacked.
        $zero = str_repeat("\0", 101 * 1024 * 1024);

        $this->assertRefused('tracker_pack_too_large', TrackerPackZip::build(['manifest.json' => '{}', 'a.bin' => $zero, 'b.bin' => $zero]));
    }

    private function assertRefused(string $code, string $archive): void
    {
        try {
            new ZipTrackerPackArchiveInspector()->inspect($archive);
            self::fail('The archive should have been refused.');
        } catch (ValidationException $e) {
            self::assertSame($code, $e->errorCode());
        }
    }
}
