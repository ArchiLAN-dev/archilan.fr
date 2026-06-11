<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Infrastructure\MinioZipOutputArtifactReader;
use App\Shared\Infrastructure\MinioStorageInterface;
use PHPUnit\Framework\TestCase;

final class MinioZipOutputArtifactReaderTest extends TestCase
{
    private const ENTRIES = [
        'AP_123_P1_Bridge.txt' => 'patch-bridge',
        'AP_123_P2_masterkafei_LM.aplm' => 'patch-lm-bytes',
        'AP_123_Spoiler.txt' => 'spoiler',
        'AP_123.archipelago' => 'multidata',
    ];

    public function testListEntriesReturnsAllBasenames(): void
    {
        $entries = $this->reader($this->buildZip(self::ENTRIES))->listEntries('sess/output/archive.zip');

        sort($entries);
        self::assertSame([
            'AP_123.archipelago',
            'AP_123_P1_Bridge.txt',
            'AP_123_P2_masterkafei_LM.aplm',
            'AP_123_Spoiler.txt',
        ], $entries);
    }

    public function testExtractEntryReturnsContentsByName(): void
    {
        $artifact = $this->reader($this->buildZip(self::ENTRIES))
            ->extractEntry('sess/output/archive.zip', 'AP_123_P2_masterkafei_LM.aplm');

        self::assertNotNull($artifact);
        self::assertSame('AP_123_P2_masterkafei_LM.aplm', $artifact->filename);
        self::assertSame('patch-lm-bytes', $artifact->contents);
    }

    public function testExtractEntryReturnsNullForUnknownName(): void
    {
        $reader = $this->reader($this->buildZip(self::ENTRIES));
        self::assertNull($reader->extractEntry('sess/output/archive.zip', 'AP_123_P9_nope.aplm'));
    }

    public function testListEntriesEmptyWhenDownloadFails(): void
    {
        $storage = $this->createStub(MinioStorageInterface::class);
        $storage->method('download')->willThrowException(new \RuntimeException('not found'));

        self::assertSame([], (new MinioZipOutputArtifactReader($storage, 'sessions'))->listEntries('missing.zip'));
    }

    public function testExtractEntryNullForEmptyKey(): void
    {
        $storage = $this->createMock(MinioStorageInterface::class);
        $storage->expects(self::never())->method('download');

        self::assertNull((new MinioZipOutputArtifactReader($storage, 'sessions'))->extractEntry('', 'x.aplm'));
    }

    private function reader(string $zipBytes): MinioZipOutputArtifactReader
    {
        $storage = $this->createStub(MinioStorageInterface::class);
        $storage->method('download')->willReturn($zipBytes);

        return new MinioZipOutputArtifactReader($storage, 'sessions');
    }

    /**
     * @param array<string, string> $entries
     */
    private function buildZip(array $entries): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ziptest_');
        self::assertNotFalse($tmp);

        $zip = new \ZipArchive();
        self::assertTrue(true === $zip->open($tmp, \ZipArchive::OVERWRITE));
        foreach ($entries as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();

        $bytes = file_get_contents($tmp);
        @unlink($tmp);
        self::assertNotFalse($bytes);

        return $bytes;
    }
}
