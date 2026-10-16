<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

/**
 * A file the launcher provides for one generation, almost always a ROM (story 44.2): the host.yaml
 * setting it answers (`DK3Settings.rom_file`, as the failure of story 44.1 names it) and the temporary
 * upload it is read from. Never stored: forwarded to the orchestrator during the request.
 */
final readonly class ProvidedGenerationFile
{
    /** Same cap as the orchestrator: the cartridge ROMs concerned are all well under it. */
    public const int MAX_BYTES = 64 * 1024 * 1024;

    public const int MAX_PER_GENERATION = 4;

    /** Group.key, as Archipelago names the setting. */
    public const string SETTING_PATTERN = '/^\w+\.\w+$/';

    public function __construct(
        public string $setting,
        public string $path,
        public string $filename,
    ) {
    }
}
