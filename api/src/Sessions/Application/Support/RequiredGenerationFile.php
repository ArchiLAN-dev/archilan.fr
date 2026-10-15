<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

/**
 * The file a world asked for during a generation (story 44.1), read from the `requiredFile` of the
 * generator's failure record: usually the player's own ROM, which ArchiLAN cannot provide yet.
 */
final readonly class RequiredGenerationFile
{
    /**
     * @param string       $setting     host.yaml setting that asked for it, e.g. `DK3Settings.rom_file`
     * @param string       $file        the file name the world expects by default
     * @param string       $extension   `.sfc`, `.z64`... - empty when the name has none
     * @param string|null  $description the world's own name for it, e.g. "DKC3 (US) ROM File"
     * @param list<string> $md5s        the checksums the world accepts, when it says
     */
    public function __construct(
        public string $setting,
        public string $file,
        public string $extension,
        public ?string $description,
        public array $md5s,
    ) {
    }

    /**
     * @param array<mixed> $record
     */
    public static function fromRecord(array $record): ?self
    {
        $setting = $record['setting'] ?? null;
        $file = $record['file'] ?? null;
        if (!is_string($setting) || '' === $setting || !is_string($file) || '' === $file) {
            return null;
        }
        $extension = $record['extension'] ?? null;
        $description = $record['description'] ?? null;
        $md5s = $record['md5s'] ?? null;

        return new self(
            $setting,
            $file,
            is_string($extension) ? $extension : '',
            is_string($description) && '' !== trim($description) ? trim($description) : null,
            is_array($md5s) ? array_values(array_filter($md5s, is_string(...))) : [],
        );
    }

    /** What the admin and the player read. */
    public function message(): string
    {
        $name = $this->description ?? $this->file;

        return sprintf(
            'Ce jeu demande le fichier « %s »%s à la génération. ArchiLAN ne sait pas encore le fournir.',
            $name,
            '' !== $this->extension && null !== $this->description ? sprintf(' (%s)', $this->extension) : '',
        );
    }
}
