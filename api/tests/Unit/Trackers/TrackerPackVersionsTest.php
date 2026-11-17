<?php

declare(strict_types=1);

namespace App\Tests\Unit\Trackers;

use App\Trackers\Domain\Entity\TrackerPackVersion;
use App\Trackers\Domain\Service\TrackerPackVersions;
use App\Trackers\Domain\ValueObject\TrackerPackManifest;
use App\Trackers\Domain\ValueObject\TrackerPackUpdate;
use PHPUnit\Framework\TestCase;

final class TrackerPackVersionsTest extends TestCase
{
    public function testCompareIgnoresALeadingVAndReadsFourSegments(): void
    {
        self::assertSame(0, TrackerPackVersions::compare('v0.9.5.3', '0.9.5.3'));
        self::assertSame(1, TrackerPackVersions::compare('0.9.5.10', 'v0.9.5.9'));
        self::assertSame(-1, TrackerPackVersions::compare('0.9.5', '0.9.5.1'));
        self::assertSame(1, TrackerPackVersions::compare('1.0.0', '0.99.9.9'));
    }

    public function testLaggingVersionsFileDoesNotHideANewerRelease(): void
    {
        $file = self::update('0.9.5.2');
        $release = self::update('0.9.5.4');

        self::assertSame($release, TrackerPackVersions::pickUpdate('0.9.5.3', $file, $release));
    }

    public function testNoUpdateWhenNothingIsAboveTheServedVersion(): void
    {
        self::assertNull(TrackerPackVersions::pickUpdate('0.9.5.3', self::update('0.9.5.2'), self::update('v0.9.5.3')));
        self::assertNull(TrackerPackVersions::pickUpdate('0.9.5.3', null, null));
    }

    public function testVersionsFileGivesItsHighestUsableEntry(): void
    {
        $update = TrackerPackVersions::fromVersionsFile(['versions' => [
            ['package_version' => '0.9.5.1', 'download_url' => 'https://github.com/o/r/a.zip'],
            ['package_version' => '0.9.6.0', 'download_url' => 'http://insecure.test/b.zip'],
            ['package_version' => '0.9.5.2', 'download_url' => 'https://github.com/o/r/c.zip', 'sha256' => str_repeat('AB', 32), 'changelog' => ['Fixed logic', '', 'New map']],
            'garbage',
        ]]);

        self::assertNotNull($update);
        self::assertSame('0.9.5.2', $update->packageVersion);
        self::assertSame('https://github.com/o/r/c.zip', $update->downloadUrl);
        self::assertSame(str_repeat('ab', 32), $update->sha256);
        self::assertSame(['Fixed logic', 'New map'], $update->changelog);
        self::assertNull(TrackerPackVersions::fromVersionsFile(['nothing' => true]));
    }

    public function testReleaseNotesAreCappedAt5000Characters(): void
    {
        $changelog = TrackerPackUpdate::changelogFromText(str_repeat("A line of notes\n", 1000));

        self::assertSame(5000, mb_strlen(implode('', $changelog)));
    }

    public function testFromTagDropsTheLeadingV(): void
    {
        self::assertSame('0.9.5.3', TrackerPackVersions::fromTag('v0.9.5.3'));
    }

    public function testRetentionKeepsTheServedVersionAndTheTwoMostRecentOthers(): void
    {
        $versions = [self::version('v1', 1), self::version('v2', 2), self::version('v3', 3), self::version('v4', 4)];

        $expired = TrackerPackVersions::expired($versions, 'v4');
        self::assertSame(['v1'], array_map(static fn (TrackerPackVersion $v): string => $v->getId(), $expired));

        // Serving an old one after a way back keeps it, and the two latest others.
        $expired = TrackerPackVersions::expired($versions, 'v1');
        self::assertSame(['v2'], array_map(static fn (TrackerPackVersion $v): string => $v->getId(), $expired));

        self::assertSame([], TrackerPackVersions::expired([self::version('v1', 1), self::version('v2', 2)], 'v2'));
    }

    private static function update(string $version): TrackerPackUpdate
    {
        return new TrackerPackUpdate($version, 'https://github.com/o/r/'.$version.'.zip', null, [], null);
    }

    private static function version(string $id, int $day): TrackerPackVersion
    {
        return TrackerPackVersion::record(
            $id,
            'pack',
            TrackerPackManifest::fromArray(['package_uid' => 'uid', 'name' => 'Pack']),
            null,
            'https://github.com/o/r/a.zip',
            str_repeat('0', 64),
            'key-'.$id,
            '',
            1,
            new \DateTimeImmutable(sprintf('2026-11-%02dT10:00:00+00:00', $day)),
        );
    }
}
