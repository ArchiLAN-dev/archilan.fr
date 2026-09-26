<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Application\Support\YamlDocumentReader;
use PHPUnit\Framework\TestCase;

/**
 * Story 38.7 review: one way to read a player's or a game's YAML document, shared by the slot
 * upgrade (38.7) and the default-YAML failure check (38.4).
 */
final class YamlDocumentReaderTest extends TestCase
{
    public function testReadsAMapping(): void
    {
        self::assertSame(['game' => 'Crystal Project', 'Crystal Project' => ['goal' => 'astley']], YamlDocumentReader::read("game: Crystal Project\nCrystal Project:\n  goal: astley\n"));
    }

    public function testIgnoresAByteOrderMark(): void
    {
        // Windows editors add one; Symfony Yaml would then read a key starting with it.
        self::assertSame(['game' => 'Crystal Project'], YamlDocumentReader::read("\u{FEFF}game: Crystal Project\n"));
    }

    public function testAnUnreadableOrEmptyDocumentIsNull(): void
    {
        self::assertNull(YamlDocumentReader::read("game: [unclosed\n"));
        self::assertNull(YamlDocumentReader::read(''));
        self::assertNull(YamlDocumentReader::read("   \n"));
        self::assertNull(YamlDocumentReader::read(null));
    }

    public function testAScalarDocumentIsNotAYamlOfOurs(): void
    {
        self::assertNull(YamlDocumentReader::read("just a sentence\n"));
    }
}
