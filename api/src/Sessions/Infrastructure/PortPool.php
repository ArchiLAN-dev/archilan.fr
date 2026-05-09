<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure;

final class PortPool
{
    /** @var list<int> */
    private array $available;

    /** @var list<int> */
    private array $allocated = [];

    public function __construct(
        private readonly int $start,
        private readonly int $end,
    ) {
        if ($start > $end) {
            throw new \InvalidArgumentException(sprintf('Port range start (%d) must be <= end (%d).', $start, $end));
        }

        $this->available = range($start, $end);
    }

    public function allocate(): ?int
    {
        if ([] === $this->available) {
            return null;
        }

        $port = array_shift($this->available);
        $this->allocated[] = $port;

        return $port;
    }

    public function release(int $port): void
    {
        if ($port < $this->start || $port > $this->end) {
            return;
        }

        $found = false;
        $newAllocated = [];
        foreach ($this->allocated as $p) {
            if (!$found && $p === $port) {
                $found = true;
            } else {
                $newAllocated[] = $p;
            }
        }

        if (!$found) {
            return;
        }

        $this->allocated = $newAllocated;
        $this->available[] = $port;
    }

    /** @param list<int> $ports */
    public function markAllocated(array $ports): void
    {
        $newAvailable = [];
        foreach ($this->available as $p) {
            if (in_array($p, $ports, true) && $p >= $this->start && $p <= $this->end) {
                $this->allocated[] = $p;
            } else {
                $newAvailable[] = $p;
            }
        }
        $this->available = $newAvailable;
    }

    public function availableCount(): int
    {
        return count($this->available);
    }

    /** @return list<int> */
    public function getAllocated(): array
    {
        return $this->allocated;
    }
}
