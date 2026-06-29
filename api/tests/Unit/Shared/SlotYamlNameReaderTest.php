<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Application\SlotYamlNameReader;
use PHPUnit\Framework\TestCase;

final class SlotYamlNameReaderTest extends TestCase
{
    public function testReadsNameField(): void
    {
        self::assertSame('MasterKafey', SlotYamlNameReader::read("name: MasterKafey\ngame: Hollow Knight\n"));
    }

    public function testStripsLeadingBom(): void
    {
        self::assertSame('Link', SlotYamlNameReader::read("\u{FEFF}name: Link\ngame: Celeste\n"));
    }

    public function testReturnsNullForEmptyYaml(): void
    {
        self::assertNull(SlotYamlNameReader::read(''));
    }

    public function testReturnsNullWhenNoNameField(): void
    {
        self::assertNull(SlotYamlNameReader::read("game: Hollow Knight\n"));
    }

    public function testReturnsNullForUnparseableYaml(): void
    {
        self::assertNull(SlotYamlNameReader::read("name: : : :\n\tbad indent"));
    }

    public function testReturnsNullWhenNameIsNotAString(): void
    {
        self::assertNull(SlotYamlNameReader::read("name:\n  - a\n  - b\n"));
    }
}
