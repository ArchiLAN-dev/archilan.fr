<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Support\InstallStepsNormalizer;
use PHPUnit\Framework\TestCase;

final class InstallStepsNormalizerTest extends TestCase
{
    private InstallStepsNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new InstallStepsNormalizer();
    }

    public function testNormalizesValidSteps(): void
    {
        $result = $this->normalizer->normalize([
            ['type' => 'note', 'title' => '  Étape  ', 'description' => '  desc '],
        ]);

        self::assertSame([], $result['errors']);
        self::assertCount(1, $result['steps']);
        self::assertSame('note', $result['steps'][0]['type']);
        self::assertSame('Étape', $result['steps'][0]['title']);
        self::assertSame('desc', $result['steps'][0]['description']);
    }

    public function testRejectsInvalidTypeAndBlankTitle(): void
    {
        $result = $this->normalizer->normalize([
            ['type' => 'bogus', 'title' => 'x'],
            ['type' => 'note', 'title' => '   '],
        ]);

        self::assertCount(2, $result['errors']);
        self::assertSame([], $result['steps']);
    }

    public function testDropsNonHttpVideoUrl(): void
    {
        $result = $this->normalizer->normalize([
            ['type' => 'note', 'title' => 'x', 'videoUrl' => 'javascript:alert(1)'],
        ]);

        self::assertNull($result['steps'][0]['videoUrl']);
    }

    public function testAssumesHttpsForSchemelessVideoUrl(): void
    {
        $result = $this->normalizer->normalize([
            ['type' => 'note', 'title' => 'x', 'videoUrl' => 'youtube.com/watch?v=abc'],
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame('https://youtube.com/watch?v=abc', $result['steps'][0]['videoUrl']);
    }

    public function testLegacyLinkAndImageKeysAreDropped(): void
    {
        // Links and images live inside the markdown description since story 31.11. A body still
        // carrying the old fields (an out-of-date client, a replayed payload) must not resurrect them.
        $result = $this->normalizer->normalize([
            [
                'type' => 'note',
                'title' => 'x',
                'description' => 'desc',
                'links' => [['label' => 'Lien', 'url' => 'https://example.org']],
                'imageKey' => 'tutorials/abc.png',
                'imageUrl' => 'https://example.org/shot.png',
            ],
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame(
            ['type', 'title', 'description', 'videoUrl'],
            array_keys($result['steps'][0]),
        );
    }

    public function testTruncatesAnOverLongDescription(): void
    {
        $long = str_repeat('a', InstallStepsNormalizer::MAX_DESCRIPTION + 50);
        $result = $this->normalizer->normalize([
            ['type' => 'note', 'title' => 'x', 'description' => $long],
        ]);

        self::assertSame(InstallStepsNormalizer::MAX_DESCRIPTION, mb_strlen($result['steps'][0]['description']));
    }
}
