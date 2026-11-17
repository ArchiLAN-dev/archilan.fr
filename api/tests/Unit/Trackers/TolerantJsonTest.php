<?php

declare(strict_types=1);

namespace App\Tests\Unit\Trackers;

use App\Trackers\Domain\Service\TolerantJson;
use PHPUnit\Framework\TestCase;

final class TolerantJsonTest extends TestCase
{
    public function testStrictJsonIsRead(): void
    {
        self::assertSame(['a' => 1, 'b' => [true, null]], TolerantJson::decode('{"a": 1, "b": [true, null]}'));
    }

    public function testBomIsIgnored(): void
    {
        self::assertSame(['a' => 'x'], TolerantJson::decode("\xEF\xBB\xBF{\"a\": \"x\"}"));
    }

    public function testCommentsAreIgnored(): void
    {
        $json = <<<'JSON'
            {
                // line comment
                "a": 1, /* block
                comment */ "b": 2
            }
            JSON;

        self::assertSame(['a' => 1, 'b' => 2], TolerantJson::decode($json));
    }

    public function testTrailingCommasAreIgnored(): void
    {
        self::assertSame(['a' => [1, 2], 'b' => ['c' => 3]], TolerantJson::decode('{"a": [1, 2,], "b": {"c": 3,},}'));
    }

    public function testCommentMarkersInsideStringsAreKept(): void
    {
        $decoded = TolerantJson::decode('{"url": "https://example.com/a,]", "q": "say \"/* hi */\""}');

        self::assertSame(['url' => 'https://example.com/a,]', 'q' => 'say "/* hi */"'], $decoded);
    }

    public function testInvalidJsonIsRefused(): void
    {
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('tracker_pack_manifest_invalid');

        TolerantJson::decode('{"a": }');
    }

    public function testScalarIsRefused(): void
    {
        $this->expectException(\DomainException::class);

        TolerantJson::decode('"just a string"');
    }
}
