<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Persisted read-model of a finished multiworld's item-exchange graph.
 *
 * Built once at archival by parsing the generation spoiler (resilient to later
 * loss of that file), keyed by session id, and rebuilt idempotently. Everything
 * is stored in slot-id space so the public page can join it to the podium
 * (RunResultsQuery) by slot id. Display names and ranking are NOT stored here -
 * they are read live from the podium so a later rename stays consistent.
 */
#[ORM\Entity]
#[ORM\Table(name: 'session_recap')]
final class SessionRecap
{
    /**
     * @param list<array{slotId: string, slotName: string, game: string}>                $nodes
     * @param list<array{fromSlotId: string, toSlotId: string, count: int}>              $edges
     * @param list<array{slotId: string, count: int}>                                    $localItems
     * @param list<array{key: string, label: string, slotId: string, value: int|string}> $superlatives
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $sessionId,

        #[ORM\Column(type: 'datetimetz_immutable')]
        private \DateTimeImmutable $generatedAt,

        #[ORM\Column(type: Types::JSON)]
        private array $nodes,

        #[ORM\Column(type: Types::JSON)]
        private array $edges,

        #[ORM\Column(name: 'local_items', type: Types::JSON)]
        private array $localItems,

        #[ORM\Column(type: Types::JSON)]
        private array $superlatives,
    ) {
    }

    /**
     * Replace the projection in place (idempotent rebuild - same session id).
     *
     * @param list<array{slotId: string, slotName: string, game: string}>                $nodes
     * @param list<array{fromSlotId: string, toSlotId: string, count: int}>              $edges
     * @param list<array{slotId: string, count: int}>                                    $localItems
     * @param list<array{key: string, label: string, slotId: string, value: int|string}> $superlatives
     */
    public function rebuild(
        \DateTimeImmutable $generatedAt,
        array $nodes,
        array $edges,
        array $localItems,
        array $superlatives,
    ): void {
        $this->generatedAt = $generatedAt;
        $this->nodes = $nodes;
        $this->edges = $edges;
        $this->localItems = $localItems;
        $this->superlatives = $superlatives;
    }

    public function getSessionId(): string
    {
        return $this->sessionId;
    }

    public function getGeneratedAt(): \DateTimeImmutable
    {
        return $this->generatedAt;
    }

    /**
     * @return list<array{slotId: string, slotName: string, game: string}>
     */
    public function getNodes(): array
    {
        return $this->nodes;
    }

    /**
     * @return list<array{fromSlotId: string, toSlotId: string, count: int}>
     */
    public function getEdges(): array
    {
        return $this->edges;
    }

    /**
     * @return list<array{slotId: string, count: int}>
     */
    public function getLocalItems(): array
    {
        return $this->localItems;
    }

    /**
     * @return list<array{key: string, label: string, slotId: string, value: int|string}>
     */
    public function getSuperlatives(): array
    {
        return $this->superlatives;
    }
}
