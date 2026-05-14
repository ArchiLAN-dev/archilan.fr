<?php

declare(strict_types=1);

namespace App\Sessions\Application\Message;

final readonly class GenerateRunJob
{
    /**
     * @param list<array{slotName: string, playerName: string, archipelagoGameName: string, playerYaml: string}> $slots
     * @param array<string, string>                                                                              $apworldDownloadUrls Storage key => pre-signed MinIO URL
     */
    public function __construct(
        public string $sessionId,
        public string $phase, // 'validate' | 'generate'
        public array $slots = [],
        public array $apworldDownloadUrls = [],
    ) {
    }
}
