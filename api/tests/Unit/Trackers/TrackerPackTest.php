<?php

declare(strict_types=1);

namespace App\Tests\Unit\Trackers;

use App\Trackers\Domain\Entity\TrackerPack;
use App\Trackers\Domain\Entity\TrackerPackVersion;
use App\Trackers\Domain\ValueObject\TrackerPackManifest;
use App\Trackers\Domain\ValueObject\TrackerPackUpdate;
use PHPUnit\Framework\TestCase;

/** Story 54.3: the update a pack keeps, and serving another of its versions. */
final class TrackerPackTest extends TestCase
{
    public function testServingTheOfferedVersionDropsTheUpdate(): void
    {
        $pack = self::pack();
        $pack->recordCheck(new TrackerPackUpdate('1.1', 'https://github.com/o/r/1.1.zip', null, ['Notes'], 'v1.1'), self::at());

        $pack->serve(self::version('v11', '1.1', ['standard']));

        self::assertSame('v11', $pack->getCurrentVersionId());
        self::assertNull($pack->getAvailableUpdate());
    }

    public function testGoingBackKeepsTheNewerUpdateOffered(): void
    {
        $pack = self::pack();
        $pack->recordCheck(new TrackerPackUpdate('1.2', 'https://github.com/o/r/1.2.zip', null, [], 'v1.2'), self::at());

        $pack->serve(self::version('v09', '0.9', ['standard']));

        self::assertSame('1.2', $pack->getAvailableUpdate()?->packageVersion);
    }

    public function testDefaultVariantMissingFromTheVersionGoesBackToTheFirst(): void
    {
        $pack = self::pack();
        $pack->chooseDefaultVariant('itemsonly', ['standard', 'itemsonly']);

        self::assertFalse($pack->serve(self::version('a', '1.1', ['standard', 'itemsonly'])));
        self::assertSame('itemsonly', $pack->getDefaultVariantUid());

        self::assertTrue($pack->serve(self::version('b', '1.2', ['standard'])));
        self::assertNull($pack->getDefaultVariantUid());
    }

    public function testVersionOfAnotherPackIsRefused(): void
    {
        $other = TrackerPackVersion::record('x', 'another-pack', TrackerPackManifest::fromArray(['package_uid' => 'uid', 'name' => 'P']), null, 'https://github.com/o/r/x.zip', str_repeat('0', 64), 'k', '', 1, self::at());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('tracker_pack_version_not_found');

        self::pack()->serve($other);
    }

    public function testFailedCheckKeepsWhatWasFound(): void
    {
        $pack = self::pack();
        $pack->recordCheck(new TrackerPackUpdate('1.1', 'https://github.com/o/r/1.1.zip', null, [], null), self::at());

        $pack->recordCheckFailure('github_rate_limited', self::at());

        self::assertSame('1.1', $pack->getAvailableUpdate()?->packageVersion);
    }

    private static function pack(): TrackerPack
    {
        return TrackerPack::import('pack', 'game', 'https://github.com/o/r', TrackerPackManifest::fromArray(['package_uid' => 'uid', 'name' => 'Pack']), 'MIT', 'v1', 'admin', self::at());
    }

    /** @param list<string> $variants */
    private static function version(string $id, string $packageVersion, array $variants): TrackerPackVersion
    {
        $manifest = TrackerPackManifest::fromArray([
            'package_uid' => 'uid',
            'name' => 'Pack',
            'package_version' => $packageVersion,
            'variants' => array_fill_keys($variants, ['display_name' => 'V', 'flags' => ['ap']]),
        ]);

        return TrackerPackVersion::record($id, 'pack', $manifest, null, 'https://github.com/o/r/a.zip', str_repeat('0', 64), 'k-'.$id, '', 1, self::at());
    }

    private static function at(): \DateTimeImmutable
    {
        return new \DateTimeImmutable('2026-11-17T10:00:00+00:00');
    }
}
