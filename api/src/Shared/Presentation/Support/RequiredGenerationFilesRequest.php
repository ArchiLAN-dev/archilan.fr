<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Support;

use App\Sessions\Application\Support\ProvidedGenerationFile;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reads the files a launcher joins to a generation (story 44.2): multipart fields
 * `requiredFiles[<Group.key>]`, the setting the failure of story 44.1 named. A request without them is
 * a plain launch.
 */
final class RequiredGenerationFilesRequest
{
    /**
     * @return array{files: list<ProvidedGenerationFile>, errors: list<string>}
     */
    public static function read(Request $request): array
    {
        $raw = $request->files->all()['requiredFiles'] ?? [];
        if (!is_array($raw) || [] === $raw) {
            return ['files' => [], 'errors' => []];
        }
        if (count($raw) > ProvidedGenerationFile::MAX_PER_GENERATION) {
            return ['files' => [], 'errors' => [sprintf('%d fichiers au plus par génération.', ProvidedGenerationFile::MAX_PER_GENERATION)]];
        }

        $files = [];
        $errors = [];
        foreach ($raw as $setting => $file) {
            $setting = (string) $setting;
            if (1 !== preg_match(ProvidedGenerationFile::SETTING_PATTERN, $setting)) {
                $errors[] = sprintf('Réglage inconnu : « %s ».', $setting);
                continue;
            }
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                $errors[] = sprintf('Fichier manquant ou invalide pour %s.', $setting);
                continue;
            }
            if ($file->getSize() > ProvidedGenerationFile::MAX_BYTES) {
                $errors[] = sprintf('Fichier trop lourd pour %s (64 Mo au plus).', $setting);
                continue;
            }
            $files[] = new ProvidedGenerationFile($setting, $file->getPathname(), $file->getClientOriginalName());
        }

        return [] === $errors ? ['files' => $files, 'errors' => []] : ['files' => [], 'errors' => $errors];
    }
}
