<?php

declare(strict_types=1);

namespace App\Sessions\Application\Message;

final readonly class GenerateRunJob
{
    /**
     * @param list<array{slotName: string, playerName: string, archipelagoGameName: string, playerYaml: string}> $slots
     * @param list<string>                                                                                       $apworldKeys Storage keys of the apworld files needed for generation
     */
    public function __construct(
        public string $sessionId,
        public string $phase, // 'validate' | 'generate'
        public array $slots = [],
        public array $apworldKeys = [],
    ) {
    }
}
