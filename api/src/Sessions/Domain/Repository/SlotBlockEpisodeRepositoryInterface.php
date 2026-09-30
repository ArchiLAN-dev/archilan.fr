<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Repository;

use App\Sessions\Domain\Entity\SlotBlockEpisode;

interface SlotBlockEpisodeRepositoryInterface
{
    /**
     * @return list<SlotBlockEpisode>
     */
    public function findBySessionId(string $sessionId): array;

    public function add(SlotBlockEpisode $episode): void;

    public function remove(SlotBlockEpisode $episode): void;

    public function flush(): void;
}
