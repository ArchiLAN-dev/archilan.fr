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

    /**
     * Runs `$work` in one transaction that holds the session's episodes lock until it commits (#1227).
     *
     * Two players pushes of the same session arrive at once: each read no episode for a slot and opened one, and the
     * second insert broke the primary key. Under the lock the second push reads what the first wrote.
     *
     * @template T
     *
     * @param \Closure(): T $work
     *
     * @return T
     */
    public function withSessionLock(string $sessionId, \Closure $work): mixed;
}
