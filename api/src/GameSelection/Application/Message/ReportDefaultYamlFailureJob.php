<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Message;

/**
 * A real generation failed for one slot (story 38.4): asks {@see \App\GameSelection\Application\Command\ReportDefaultYamlFailure}
 * whether the apworld is at fault. Dispatched after the failure is committed, so the source never
 * lengthens its own transaction.
 */
final readonly class ReportDefaultYamlFailureJob
{
    public function __construct(
        public string $gameId,
        public ?string $apworldHash,
        public string $playerYaml,
        public string $error,
    ) {
    }
}
