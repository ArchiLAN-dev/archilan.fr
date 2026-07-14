<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Application\Support\PhpSource;
use PHPUnit\Framework\TestCase;

final class PhpSourceTest extends TestCase
{
    public function testDocCommentsAreBlanked(): void
    {
        $code = PhpSource::fromString(<<<'PHP'
            <?php
            /** Never write new \DateTimeImmutable() here. */
            final class A
            {
            }
            PHP)->codeText();

        self::assertStringNotContainsString('new \DateTimeImmutable()', $code);
        self::assertStringContainsString('final class A', $code);
    }

    public function testLineCommentsAreBlanked(): void
    {
        $code = PhpSource::fromString(<<<'PHP'
            <?php
            // public function setTitle(string $t): void
            final class A
            {
            }
            PHP)->codeText();

        self::assertStringNotContainsString('public function setTitle', $code);
    }

    public function testSingleQuotedStringLiteralsAreBlanked(): void
    {
        $code = PhpSource::fromString(<<<'PHP'
            <?php
            $forbidden = 'App\Events\Infrastructure\DoctrineEventRepository';
            PHP)->codeText();

        self::assertStringNotContainsString('App\Events\Infrastructure', $code);
        self::assertStringContainsString('$forbidden', $code);
    }

    public function testHeredocBodiesAreBlanked(): void
    {
        $code = PhpSource::fromString(<<<'PHP'
            <?php
            $doc = <<<'TXT'
                public function setTitle(string $t): void
                TXT;
            PHP)->codeText();

        self::assertStringNotContainsString('public function setTitle', $code);
    }

    /**
     * The literal chunks of an interpolating string are prose; the interpolated expression
     * is code. Blanking the chunk must not blank the variable.
     */
    public function testInterpolatedStringKeepsItsCodeButLosesItsLiteralChunk(): void
    {
        $code = PhpSource::fromString(<<<'PHP'
            <?php
            $msg = "setTitle is banned: {$name}";
            PHP)->codeText();

        self::assertStringNotContainsString('setTitle is banned', $code);
        self::assertStringContainsString('$name', $code);
    }

    public function testRealCodeSurvivesIntact(): void
    {
        $code = PhpSource::fromString(<<<'PHP'
            <?php
            namespace App\Events\Domain\Entity;

            final class Poster
            {
                public function setTitle(string $title): void
                {
                    $this->title = $title;
                }
            }
            PHP)->codeText();

        // A REAL setter is code and must still be found - the point is to stop matching
        // phantoms, not to stop matching violations.
        self::assertStringContainsString('public function setTitle', $code);
        self::assertStringContainsString('namespace App\Events\Domain\Entity;', $code);
    }

    /**
     * Violation messages carry line numbers, and some rules slice by offset. Blanking must
     * be length- and newline-preserving or every one of those would shift.
     */
    public function testOffsetsAndLineNumbersArePreserved(): void
    {
        $source = <<<'PHP'
            <?php
            /**
             * new \DateTimeImmutable()
             */
            final class A
            {
            }
            PHP;

        $code = PhpSource::fromString($source)->codeText();

        self::assertSame(strlen($source), strlen($code), 'blanking must preserve byte length');
        self::assertSame(
            substr_count($source, "\n"),
            substr_count($code, "\n"),
            'blanking must preserve newlines, or line numbers shift',
        );
    }

    /**
     * Text outside `<?php` is T_INLINE_HTML - not code, and it cannot violate a PHP rule.
     * It blanks to whitespace, so no rule fires on a non-PHP file. That is the safe
     * direction: the alternative (keeping it) is what let prose match a code pattern in the
     * first place.
     */
    public function testTextOutsidePhpTagsIsNotCode(): void
    {
        $code = PhpSource::fromString('public function setTitle() - just prose')->codeText();

        self::assertSame('', trim($code));
    }

    public function testFromFileReturnsNullWhenUnreadable(): void
    {
        self::assertNull(PhpSource::fromFile(sys_get_temp_dir().'/definitely-not-here-'.bin2hex(random_bytes(6))));
    }
}
