<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Domain\ValueObject\ApworldVersion;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApworldVersionTest extends TestCase
{
    #[DataProvider('readableTags')]
    public function testReadsTheVersionOutOfAnyTag(string $tag, string $expected): void
    {
        self::assertSame($expected, ApworldVersion::parse($tag)?->toString());
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function readableTags(): iterable
    {
        yield 'plain semver' => ['0.18.2', '0.18.2'];
        yield 'v prefix' => ['v0.18.2', '0.18.2'];
        yield 'capital V prefix' => ['V1.2.3', '1.2.3'];
        yield 'project prefix (Crystal Project tags)' => ['CrystalProject-v0.18.2', '0.18.2'];
        yield 'release name with words' => ['Crystal Project Version 0.18.2', '0.18.2'];
        yield 'two components' => ['v1.4', '1.4.0'];
        yield 'pre-release suffix' => ['v2.0.0-beta.1', '2.0.0-beta.1'];
        yield 'the last version number wins' => ['for AP 0.6.7 - v1.3.0', '1.3.0'];
    }

    public function testReturnsNullWithoutAnyVersionNumber(): void
    {
        self::assertNull(ApworldVersion::parse('latest'));
        self::assertNull(ApworldVersion::parse(''));
        self::assertNull(ApworldVersion::parse('release-42'));
    }

    public function testOrdersNumericallyNotLexically(): void
    {
        self::assertTrue($this->version('0.10.0')->isNewerThan($this->version('0.9.0')));
        self::assertFalse($this->version('0.9.0')->isNewerThan($this->version('0.10.0')));
    }

    public function testComparesMajorThenMinorThenPatch(): void
    {
        self::assertTrue($this->version('1.0.0')->isNewerThan($this->version('0.99.99')));
        self::assertTrue($this->version('0.18.0')->isNewerThan($this->version('0.17.9')));
        self::assertTrue($this->version('0.18.2')->isNewerThan($this->version('0.18.1')));
    }

    public function testAnOlderVersionIsNeverNewer(): void
    {
        // The case the string comparison got wrong: a tracker pointing at 0.16.0 while 0.17.0 is deployed.
        self::assertFalse($this->version('CrystalProject-v0.16.0')->isNewerThan($this->version('CrystalProject-v0.17.0')));
    }

    public function testEqualVersionsWrittenDifferentlyAreEqual(): void
    {
        self::assertTrue($this->version('v1.4')->equals($this->version('1.4.0')));
        self::assertTrue($this->version('CrystalProject-v0.18.2')->equals($this->version('0.18.2')));
        self::assertFalse($this->version('1.4.0')->isNewerThan($this->version('v1.4')));
    }

    public function testPreReleaseIsLowerThanItsFinalVersion(): void
    {
        self::assertTrue($this->version('2.0.0')->isNewerThan($this->version('2.0.0-rc.1')));
        self::assertFalse($this->version('2.0.0-rc.1')->isNewerThan($this->version('2.0.0')));
        self::assertTrue($this->version('2.0.0-rc.1')->isNewerThan($this->version('1.9.9')));
    }

    public function testPreReleasesCompareNaturally(): void
    {
        self::assertTrue($this->version('2.0.0-beta.10')->isNewerThan($this->version('2.0.0-beta.9')));
        self::assertTrue($this->version('2.0.0-rc.1')->isNewerThan($this->version('2.0.0-beta.2')));
    }

    private function version(string $tag): ApworldVersion
    {
        $version = ApworldVersion::parse($tag);
        self::assertNotNull($version, sprintf('"%s" should be readable', $tag));

        return $version;
    }
}
