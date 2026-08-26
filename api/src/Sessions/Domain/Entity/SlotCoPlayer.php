<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

/**
 * Someone who plays a slot they do not own (story 16.17).
 *
 * Some games are not played alone - a Minecraft world is rarely one person in front of one screen -
 * and Archipelago has no notion of it: three people on the same world share one slot. Before this
 * entity only one of them existed for the platform, which meant no patch, no hints, and no points
 * for the others.
 *
 * The row points at the **game slot id**, not at a SessionSlot row: `SessionSlot.slotId` carries
 * that id, and it is the only identifier that exists *before* a run is launched and that survives a
 * relaunch (which throws away the session and its slots but keeps the participants' game slots).
 * Anchoring on the session slot would have made co-players unassignable until launch and lost on
 * every restart.
 *
 * The owner is not stored here: it stays `SessionSlot.registrationId`, the column that ties a slot
 * to the configuration and yaml its owner declared.
 */
#[ORM\Entity]
#[ORM\Table(name: 'slot_co_player')]
#[ORM\UniqueConstraint(name: 'uniq_slot_co_player', columns: ['slot_id', 'user_id'])]
final class SlotCoPlayer
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: Types::STRING, length: 36)]
        private string $id,

        #[ORM\Column(name: 'slot_id', type: Types::STRING, length: 36)]
        private string $slotId,

        #[ORM\Column(name: 'user_id', type: Types::STRING, length: 36)]
        private string $userId,

        #[ORM\Column(name: 'added_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $addedAt,
    ) {
    }

    public static function create(string $id, string $slotId, string $userId, \DateTimeImmutable $now): self
    {
        return new self($id, $slotId, $userId, $now);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getSlotId(): string
    {
        return $this->slotId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getAddedAt(): \DateTimeImmutable
    {
        return $this->addedAt;
    }
}
