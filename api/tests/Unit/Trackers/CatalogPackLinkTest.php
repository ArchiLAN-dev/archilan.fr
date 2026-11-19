<?php

declare(strict_types=1);

namespace App\Tests\Unit\Trackers;

use App\Trackers\Domain\ValueObject\CatalogPackLink;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Story 54.12: reading the PopTracker links of the community catalog. */
final class CatalogPackLinkTest extends TestCase
{
    /** @return iterable<string, array{string, string, string}> */
    public static function githubLinks(): iterable
    {
        yield 'latest release' => ['https://github.com/palex00/aquaria-ap-tracker/releases/latest', 'palex00', 'aquaria-ap-tracker'];
        yield 'pinned tag' => ['https://github.com/jeditobe1/bg3-ap-tracker/releases/tag/v0.7.0', 'jeditobe1', 'bg3-ap-tracker'];
        yield 'releases page' => ['https://github.com/Michpem/SOTN-AP-MapTracker/releases', 'Michpem', 'SOTN-AP-MapTracker'];
        yield 'repository' => ['https://github.com/ZobeePlays/Adventure-AP-Tracker', 'ZobeePlays', 'Adventure-AP-Tracker'];
        yield 'google redirect' => [
            'https://www.google.com/url?q=https://github.com/JAHapner01/Grinch-AP-Tracker/releases/latest&sa=D&source=editors&ust=1&usg=x',
            'JAHapner01',
            'Grinch-AP-Tracker',
        ];
    }

    #[DataProvider('githubLinks')]
    public function testGithubLinkIsImportedFromTheRepository(string $url, string $owner, string $repo): void
    {
        $link = CatalogPackLink::fromCatalog('Poptracker', $url);

        self::assertNotNull($link);
        self::assertTrue($link->importable());
        self::assertSame($owner, $link->owner);
        self::assertSame($repo, $link->repo);
        self::assertSame(sprintf('https://github.com/%s/%s', $owner, $repo), $link->importUrl());
    }

    public function testLinkOutsideGithubIsOnlyShown(): void
    {
        foreach (['https://discord.com/channels/1/2/3', 'https://git.ddd.rip/SophiaNya/ae3-poptracker-pack/releases'] as $url) {
            $link = CatalogPackLink::fromCatalog('Poptracker (Discord)', $url);

            self::assertNotNull($link);
            self::assertFalse($link->importable());
            self::assertNull($link->importUrl());
            self::assertSame($url, $link->url);
        }
    }

    public function testOtherLabelsAreNotPacks(): void
    {
        foreach (['Tracker (Web)', 'Emotracker', 'Universal Tracker Maps', 'Github Releases', 'Tracker'] as $label) {
            self::assertNull(CatalogPackLink::fromCatalog($label, 'https://github.com/someone/something'));
        }
    }

    public function testLabelCaseIsIgnoredAndALinkWithoutAddressIsSkipped(): void
    {
        self::assertNotNull(CatalogPackLink::fromCatalog(' POPTRACKER ', 'https://github.com/a/b'));
        self::assertNull(CatalogPackLink::fromCatalog('Poptracker', null));
        self::assertNull(CatalogPackLink::fromCatalog('Poptracker', ' '));
    }

    public function testSameRepositoryIgnoresCaseAndTheReleaseInTheLink(): void
    {
        $link = CatalogPackLink::fromCatalog('Poptracker', 'https://github.com/Br00ty/tloz_brooty/releases/latest');
        self::assertNotNull($link);

        self::assertTrue($link->sameRepository('https://github.com/br00ty/TLOZ_BROOTY'));
        self::assertTrue($link->sameRepository('https://github.com/Br00ty/tloz_brooty/releases/tag/1.2'));
        self::assertFalse($link->sameRepository('https://github.com/Br00ty/other_pack'));
        self::assertFalse($link->sameRepository('not a link'));
    }

    /** Story 54.13: the sheet is edited by anyone, only an https link reaches an admin. */
    public function testOnlyHttpsLinksAreKept(): void
    {
        self::assertNull(CatalogPackLink::fromCatalog('Poptracker', 'data:text/html,<script>alert(1)</script>'));
        self::assertNull(CatalogPackLink::fromCatalog('Poptracker', 'http://example.org/pack'));
        self::assertNull(CatalogPackLink::fromCatalog('Poptracker', 'https://www.google.com/url?q=javascript:alert(1)&sa=D'));
        self::assertNotNull(CatalogPackLink::fromCatalog('Poptracker', 'https://git.ddd.rip/pack'));
    }
}
