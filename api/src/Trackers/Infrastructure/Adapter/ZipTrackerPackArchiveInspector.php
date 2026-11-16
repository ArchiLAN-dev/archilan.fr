<?php

declare(strict_types=1);

namespace App\Trackers\Infrastructure\Adapter;

use App\Shared\Application\Exception\ValidationException;
use App\Trackers\Application\Port\TrackerPackArchiveInspectorInterface;
use App\Trackers\Application\Support\InspectedTrackerPackArchive;
use App\Trackers\Domain\Entity\TrackerPackVersion;

/**
 * Checks a pack zip by its central directory, without unpacking anything to disk (story 54.1): number of files,
 * declared unpacked size, dangerous paths, and where `manifest.json` sits. Only the manifest is read, capped.
 */
final readonly class ZipTrackerPackArchiveInspector implements TrackerPackArchiveInspectorInterface
{
    private const int MAX_MANIFEST_BYTES = 1024 * 1024;

    /** Deepest the manifest may sit: at the root, or in the single folder a GitHub source archive adds. */
    private const int MAX_MANIFEST_DEPTH = 1;

    public function inspect(string $archive): InspectedTrackerPackArchive
    {
        if (strlen($archive) > TrackerPackVersion::MAX_ARCHIVE_BYTES) {
            throw self::refused('tracker_pack_too_large', 'Le pack dépasse la taille autorisée (50 Mo compressé, 200 Mo décompressé, 5000 fichiers).');
        }
        $tmp = tempnam(sys_get_temp_dir(), 'tracker_pack_');
        if (false === $tmp) {
            throw new \RuntimeException('tracker_pack.tempfile_failed');
        }

        try {
            if (false === file_put_contents($tmp, $archive)) {
                throw new \RuntimeException('tracker_pack.tempfile_failed');
            }
            $zip = new \ZipArchive();
            if (true !== $zip->open($tmp, \ZipArchive::RDONLY)) {
                throw self::refused('tracker_pack_archive_invalid', 'Le fichier téléchargé n\'est pas un zip lisible.');
            }

            try {
                return $this->inspectOpen($zip);
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($tmp);
        }
    }

    private function inspectOpen(\ZipArchive $zip): InspectedTrackerPackArchive
    {
        if ($zip->numFiles > TrackerPackVersion::MAX_FILES) {
            throw self::refused('tracker_pack_too_large', 'Le pack dépasse la taille autorisée (50 Mo compressé, 200 Mo décompressé, 5000 fichiers).');
        }

        $unpacked = 0;
        $manifestIndex = null;
        $manifestDepth = PHP_INT_MAX;
        for ($i = 0; $i < $zip->numFiles; ++$i) {
            $stat = $zip->statIndex($i);
            if (false === $stat) {
                throw self::refused('tracker_pack_archive_invalid', 'Le fichier téléchargé n\'est pas un zip lisible.');
            }
            $name = $stat['name'];
            if (self::isUnsafe($name)) {
                throw self::refused('tracker_pack_unsafe_path', 'Le zip contient un chemin dangereux, il est refusé.');
            }
            $unpacked += $stat['size'];
            if ($unpacked > TrackerPackVersion::MAX_UNPACKED_BYTES) {
                throw self::refused('tracker_pack_too_large', 'Le pack dépasse la taille autorisée (50 Mo compressé, 200 Mo décompressé, 5000 fichiers).');
            }
            if ('manifest.json' === basename($name) && !str_ends_with($name, '/')) {
                $depth = substr_count($name, '/');
                if ($depth <= self::MAX_MANIFEST_DEPTH && $depth < $manifestDepth) {
                    $manifestIndex = $i;
                    $manifestDepth = $depth;
                    if ($stat['size'] > self::MAX_MANIFEST_BYTES) {
                        throw self::refused('tracker_pack_manifest_invalid', 'Le manifest.json du pack est illisible ou incomplet (package_uid et name sont obligatoires).');
                    }
                }
            }
        }

        if (null === $manifestIndex) {
            throw self::refused('tracker_pack_manifest_missing', 'Aucun manifest.json à la racine du zip : ce n\'est pas un pack PopTracker.');
        }
        $name = $zip->getNameIndex($manifestIndex);
        $manifest = $zip->getFromIndex($manifestIndex, self::MAX_MANIFEST_BYTES);
        if (false === $name || false === $manifest) {
            throw self::refused('tracker_pack_manifest_invalid', 'Le manifest.json du pack est illisible ou incomplet (package_uid et name sont obligatoires).');
        }
        $root = dirname($name);

        return new InspectedTrackerPackArchive('.' === $root ? '' : $root, $manifest);
    }

    /** An entry that could escape the folder it is unpacked in, on any system. */
    private static function isUnsafe(string $name): bool
    {
        if ('' === $name
            || str_starts_with($name, '/')
            || str_contains($name, '\\')
            || str_contains($name, "\0")
            || 1 === preg_match('/^[A-Za-z]:/', $name)) {
            return true;
        }

        return in_array('..', explode('/', $name), true);
    }

    private static function refused(string $code, string $message): ValidationException
    {
        return new ValidationException($message, ['url' => [$message]], $code);
    }
}
