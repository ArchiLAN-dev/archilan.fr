<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure;

use App\Sessions\Application\SessionSpoilerArtifactReaderInterface;
use App\Sessions\Application\SpoilerArtifact;
use App\Shared\Infrastructure\MinioStorageInterface;

/**
 * Downloads the session output archive from MinIO and extracts the single spoiler entry.
 *
 * The orchestrateur stores the whole generated output (multidata + per-player patches +
 * spoiler) as a flat zip under `{sessionId}/output/archive.zip` in the sessions bucket.
 * We return ONLY the `*_spoiler*` entry and never the `.archipelago` multidata - the
 * multidata is itself a full spoiler and must not leak through this reader.
 */
final readonly class MinioZipSpoilerArtifactReader implements SessionSpoilerArtifactReaderInterface
{
    public function __construct(
        private MinioStorageInterface $storage,
        private string $minioSessionsBucket,
    ) {
    }

    public function extractSpoiler(string $outputKey): ?SpoilerArtifact
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

        $tmp = tempnam(sys_get_temp_dir(), 'spoiler_');
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
                for ($i = 0; $i < $zip->numFiles; ++$i) {
                    $name = $zip->getNameIndex($i);
                    if (false === $name) {
                        continue;
                    }

                    $base = basename($name);
                    $lower = strtolower($base);
                    if (!str_contains($lower, '_spoiler')) {
                        continue;
                    }
                    if (str_ends_with($lower, '.archipelago')) {
                        continue;
                    }

                    $contents = $zip->getFromIndex($i);
                    if (false === $contents) {
                        return null;
                    }

                    return new SpoilerArtifact($base, $contents);
                }
            } finally {
                $zip->close();
            }

            return null;
        } finally {
            @unlink($tmp);
        }
    }
}
