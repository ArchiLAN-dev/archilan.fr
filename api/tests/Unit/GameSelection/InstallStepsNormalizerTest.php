<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\InstallStepsNormalizer;
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
            ['type' => 'note', 'title' => '  Étape  ', 'description' => '  desc ', 'links' => [
                ['label' => '  Lien ', 'url' => 'https://example.org'],
            ]],
        ]);

        self::assertSame([], $result['errors']);
        self::assertCount(1, $result['steps']);
        self::assertSame('note', $result['steps'][0]['type']);
        self::assertSame('Étape', $result['steps'][0]['title']);
        self::assertSame('desc', $result['steps'][0]['description']);
        self::assertSame([['label' => 'Lien', 'url' => 'https://example.org']], $result['steps'][0]['links']);
    }

    public function testRejectsInvalidTypeAndBlankTitle(): void
    {
        $result = $this->normalizer->normalize([
            ['type' => 'bogus', 'title' => 'x', 'links' => []],
            ['type' => 'note', 'title' => '   ', 'links' => []],
        ]);

        self::assertCount(2, $result['errors']);
        self::assertSame([], $result['steps']);
    }

    public function testDropsNonHttpLinkAndRecordsError(): void
    {
        $result = $this->normalizer->normalize([
            ['type' => 'note', 'title' => 'x', 'links' => [
                ['label' => 'evil', 'url' => 'javascript:alert(1)'],
                ['label' => 'ok', 'url' => 'http://example.org'],
            ]],
        ]);

        self::assertCount(1, $result['errors']);
        self::assertCount(1, $result['steps']);
        self::assertSame([['label' => 'ok', 'url' => 'http://example.org']], $result['steps'][0]['links']);
    }

    public function testAssumesHttpsForSchemelessUrl(): void
    {
        $result = $this->normalizer->normalize([
            ['type' => 'note', 'title' => 'x', 'links' => [['label' => 'site', 'url' => 'example.org/guide']]],
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame('https://example.org/guide', $result['steps'][0]['links'][0]['url']);
    }

    public function testCarriesAndSanitizesMediaUrls(): void
    {
        $result = $this->normalizer->normalize([
            ['type' => 'note', 'title' => 'x', 'links' => [], 'imageUrl' => 'example.org/shot.png', 'videoUrl' => 'javascript:alert(1)'],
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame('https://example.org/shot.png', $result['steps'][0]['imageUrl']);
        self::assertNull($result['steps'][0]['videoUrl']);
    }

    public function testKeepsNullUrlAndDropsEmptyLabel(): void
    {
        $result = $this->normalizer->normalize([
            ['type' => 'note', 'title' => 'x', 'links' => [
                ['label' => 'Label only', 'url' => null],
                ['label' => '', 'url' => 'https://example.org'],
            ]],
        ]);

        self::assertSame([], $result['errors']);
        self::assertSame([['label' => 'Label only', 'url' => null]], $result['steps'][0]['links']);
    }
}
