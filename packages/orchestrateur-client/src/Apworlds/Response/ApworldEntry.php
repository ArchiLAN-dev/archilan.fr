<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Apworlds\Response;

use Archilan\OrchestratorClient\Exception\OrchestratorException;

final readonly class ApworldEntry
{
    public function __construct(
        public string $hash,
        public string $game,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $hash = $data['hash'] ?? null;
        if (!is_string($hash) || '' === $hash) {
            throw new OrchestratorException("Missing or invalid field 'hash' in apworld list response");
        }

        $game = $data['game'] ?? null;
        if (!is_string($game)) {
            throw new OrchestratorException("Missing or invalid field 'game' in apworld list response");
        }

        return new self(hash: $hash, game: $game);
    }
}
