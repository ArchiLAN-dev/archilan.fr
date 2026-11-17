<?php

declare(strict_types=1);

namespace App\Tests\Unit\Trackers;

use App\Tests\Fixtures\Trackers\TrackerPackZip;
use App\Trackers\Domain\Service\TolerantJson;
use App\Trackers\Domain\ValueObject\TrackerPackManifest;
use PHPUnit\Framework\TestCase;

final class TrackerPackManifestTest extends TestCase
{
    public function testMajorasMaskManifestIsRead(): void
    {
        $manifest = TrackerPackManifest::fromArray(TolerantJson::decode(TrackerPackZip::MANIFEST));

        self::assertSame('MM_AP', $manifest->packageUid);
        self::assertSame("Majora's Mask: Recompiled", $manifest->name);
        self::assertSame('0.9.5.3', $manifest->packageVersion);
        self::assertSame('Seto10987, G4M3R L1F3', $manifest->author);
        self::assertSame('The Legend of Zelda - Majoras Mask', $manifest->gameName);
        self::assertSame('0.32.0', $manifest->minPopTrackerVersion);
        self::assertSame('https://raw.githubusercontent.com/o/r/refs/heads/cube/versions.json', $manifest->versionsUrl);
        self::assertTrue($manifest->hasArchipelagoVariant());
        self::assertSame([
            ['uid' => 'standard', 'displayName' => 'Maps and items', 'flags' => ['ap']],
            ['uid' => 'itemsonly', 'displayName' => 'Items only', 'flags' => ['ap']],
        ], $manifest->variantsAsArray());
    }

    public function testPackWithoutVariantsGetsAStandardOne(): void
    {
        $manifest = TrackerPackManifest::fromArray(['package_uid' => 'x', 'name' => 'X']);

        self::assertSame([['uid' => '', 'displayName' => 'Standard', 'flags' => []]], $manifest->variantsAsArray());
        self::assertFalse($manifest->hasArchipelagoVariant());
        self::assertNull($manifest->packageVersion);
    }

    public function testVariantWithoutNameUsesItsUid(): void
    {
        $manifest = TrackerPackManifest::fromArray(['package_uid' => 'x', 'name' => 'X', 'variants' => ['maps' => ['flags' => ['ap', 'ap', 3]]]]);

        self::assertSame([['uid' => 'maps', 'displayName' => 'maps', 'flags' => ['ap']]], $manifest->variantsAsArray());
    }

    public function testNumericVersionIsKeptAsText(): void
    {
        self::assertSame('2', TrackerPackManifest::fromArray(['package_uid' => 'x', 'name' => 'X', 'package_version' => 2])->packageVersion);
    }

    public function testNonHttpsVersionsUrlIsDropped(): void
    {
        self::assertNull(TrackerPackManifest::fromArray(['package_uid' => 'x', 'name' => 'X', 'versions_url' => 'http://a/v.json'])->versionsUrl);
    }

    /** @return iterable<string, array{array<mixed>}> */
    public static function invalidManifests(): iterable
    {
        yield 'no uid' => [['name' => 'X']];
        yield 'blank name' => [['package_uid' => 'x', 'name' => '  ']];
        yield 'uid not text' => [['package_uid' => ['x'], 'name' => 'X']];
        yield 'uid too long' => [['package_uid' => str_repeat('u', 121), 'name' => 'X']];
        yield 'variants as list' => [['package_uid' => 'x', 'name' => 'X', 'variants' => [['flags' => []]]]];
        yield 'variant not object' => [['package_uid' => 'x', 'name' => 'X', 'variants' => ['a' => 'b']]];
        yield 'flags not list' => [['package_uid' => 'x', 'name' => 'X', 'variants' => ['a' => ['flags' => 'ap']]]];
    }

    /** @param array<mixed> $data */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidManifests')]
    public function testIncompleteManifestIsRefused(array $data): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('tracker_pack_manifest_invalid');

        TrackerPackManifest::fromArray($data);
    }
}
