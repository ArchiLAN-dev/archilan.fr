<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Query;

/**
 * An admin as the health page shows them: who took an incident, who closed it (story 38.3).
 */
final readonly class ApworldIncidentAdmin
{
    public function __construct(
        public string $id,
        public string $displayName,
    ) {
    }

    /**
     * @return array{id: string, displayName: string}
     */
    public function toArray(): array
    {
        return ['id' => $this->id, 'displayName' => $this->displayName];
    }
}
