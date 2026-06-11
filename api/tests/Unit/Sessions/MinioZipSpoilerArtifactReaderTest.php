<?php

declare(strict_types=1);

namespace App\Tests\Unit\Sessions;

use App\Sessions\Infrastructure\MinioZipSpoilerArtifactReader;
use App\Shared\Infrastructure\MinioStorageInterface;
use PHPUnit\Framework\TestCase;

final class MinioZipSpoilerArtifactReaderTest extends TestCase
{
    public function testExtractsOnlyTheSpoilerEntry(): void
    {
        $zip = $this->buildZip([
            'AP_123_P1_Bridge.txt' => 'patch-bridge',
            'AP_123_P2_masterkafei_LM.aplm' => 'patch-lm',
            'AP_123_Spoiler.txt' => 'SPOILER CONTENT',
            'AP_123.archipelago' => 'multidata-bytes',
        ]);

        $artifact = $this->reader($zip)->extractSpoiler('sess/output/archive.zip');

        self::assertNotNull($artifact);
        self::assertSame('AP_123_Spoiler.txt', $artifact->filename);
        self::assertSame('SPOILER CONTENT', $artifact->contents);
    }

    public function testReturnsNullWhenArchiveHasNoSpoiler(): void
    {
        $zip = $this->buildZip([
            'AP_123.archipelago' => 'multidata-bytes',
            'AP_123_P2_masterkafei_LM.aplm' => 'patch-lm',
        ]);

        self::assertNull($this->reader($zip)->extractSpoiler('sess/output/archive.zip'));
    }

    public function testReturnsNullWhenDownloadFails(): void
    {
        $storage = $this->createStub(MinioStorageInterface::class);
        $storage->method('download')->willThrowException(new \RuntimeException('object not found'));

        self::assertNull((new MinioZipSpoilerArtifactReader($storage, 'sessions'))->extractSpoiler('missing.zip'));
    }

    public function testReturnsNullForEmptyKey(): void
    {
        $storage = $this->createMock(MinioStorageInterface::class);
        $storage->expects(self::never())->method('download');

        self::assertNull((new MinioZipSpoilerArtifactReader($storage, 'sessions'))->extractSpoiler(''));
    }

    private function reader(string $zipBytes): MinioZipSpoilerArtifactReader
    {
        $storage = $this->createStub(MinioStorageInterface::class);
        $storage->method('download')->willReturn($zipBytes);

        return new MinioZipSpoilerArtifactReader($storage, 'sessions');
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
