<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure;

use App\Sessions\Application\SessionOutputArtifact;
use App\Sessions\Application\SessionOutputArtifactReaderInterface;
use App\Shared\Infrastructure\MinioStorageInterface;

/**
 * Reads a session's generated output archive from MinIO (a flat zip of multidata +
 * per-player patches + spoiler) and lists/extracts files from it. The whole archive is
 * downloaded per call - archives are small; a manifest-based optimisation is possible later.
 */
final readonly class MinioZipOutputArtifactReader implements SessionOutputArtifactReaderInterface
{
    public function __construct(
        private MinioStorageInterface $storage,
        private string $minioSessionsBucket,
    ) {
    }

    public function listEntries(string $outputKey): array
    {
        return $this->withArchive($outputKey, static function (\ZipArchive $zip): array {
            $names = [];
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                if (false === $name) {
                    continue;
                }
                $base = basename($name);
                if ('' !== $base) {
                    $names[] = $base;
                }
            }

            return $names;
        }) ?? [];
    }

    public function extractEntry(string $outputKey, string $entryName): ?SessionOutputArtifact
    {
        $target = basename($entryName);
        if ('' === $target) {
            return null;
        }

        return $this->withArchive($outputKey, static function (\ZipArchive $zip) use ($target): ?SessionOutputArtifact {
            for ($i = 0; $i < $zip->numFiles; ++$i) {
                $name = $zip->getNameIndex($i);
                if (false === $name || basename($name) !== $target) {
                    continue;
                }

                $contents = $zip->getFromIndex($i);
                if (false === $contents) {
                    return null;
                }

                return new SessionOutputArtifact($target, $contents);
            }

            return null;
        });
    }

    /**
     * Downloads the archive, opens it as a zip, runs $fn on it, and always cleans up.
     *
     * @template T
     *
     * @param callable(\ZipArchive): T $fn
     *
     * @return T|null null when the archive is missing/unreadable
     */
    private function withArchive(string $outputKey, callable $fn): mixed
    {
        if ('' === $outputKey) {
            return null;
        }

        try {
            $archive = $this->storage->download($this->minioSessionsBucket, $outputKey);
        } catch (\Throwable) {
            return null;
        }
        if ('' === $archive) {
            return null;
        }

        $tmp = tempnam(sys_get_temp_dir(), 'output_');
        if (false === $tmp) {
            return null;
        }

        try {
            if (false === file_put_contents($tmp, $archive)) {
                return null;
            }

            $zip = new \ZipArchive();
            if (true !== $zip->open($tmp)) {
                return null;
            }

            try {
                return $fn($zip);
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($tmp);
        }
    }
}
