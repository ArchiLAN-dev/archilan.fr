<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Query;

/**
 * One row of the apworld health page (story 38.3). `summary` is the one-line reading of `error`,
 * computed on the API side so the frontend never re-implements the generator log parsing.
 */
final readonly class ApworldIncidentListItem
{
    public function __construct(
        public string $id,
        public string $gameId,
        public string $gameName,
        public string $apworldHash,
        public string $type,
        public string $status,
        public string $summary,
        public string $error,
        public \DateTimeImmutable $openedAt,
        public \DateTimeImmutable $lastSeenAt,
        public int $occurrences,
        public ?ApworldIncidentAdmin $acknowledgedBy,
        public ?\DateTimeImmutable $acknowledgedAt,
        public ?\DateTimeImmutable $closedAt,
        public ?ApworldIncidentAdmin $closedBy,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'gameId' => $this->gameId,
            'gameName' => $this->gameName,
            'apworldHash' => $this->apworldHash,
            'type' => $this->type,
            'status' => $this->status,
            'summary' => $this->summary,
            'error' => $this->error,
            'openedAt' => $this->openedAt->format(\DATE_ATOM),
            'lastSeenAt' => $this->lastSeenAt->format(\DATE_ATOM),
            'occurrences' => $this->occurrences,
            'acknowledgedBy' => $this->acknowledgedBy?->toArray(),
            'acknowledgedAt' => $this->acknowledgedAt?->format(\DATE_ATOM),
            'closedAt' => $this->closedAt?->format(\DATE_ATOM),
            'closedBy' => $this->closedBy?->toArray(),
            'closedAutomatically' => null !== $this->closedAt && null === $this->closedBy,
        ];
    }
}
