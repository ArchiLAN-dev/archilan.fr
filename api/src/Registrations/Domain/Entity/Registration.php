<?php

declare(strict_types=1);

namespace App\Registrations\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_event_registrations_event_user', columns: ['event_id', 'user_id'])]
final class Registration
{
    public const string STATUS_RESERVED = 'reserved';
    public const string STATUS_CANCELLED = 'cancelled';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'event_id', type: 'string', length: 32)]
        private string $eventId,
        #[ORM\Column(name: 'user_id', type: 'string', length: 32)]
        private string $userId,
        #[ORM\Column(type: 'string', length: 20)]
        private string $status,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
        /**
         * Ordered list of game slots. Each slot is independent and may reference the same gameId
         * as another slot (e.g. two Hollow Knight instances with different player configs).
         *
         * @var list<array{slotId: string, gameId: string, slotOrder: int, apworldHash?: string|null, playerYaml?: string|null, needsReview?: list<string>}>
         */
        #[ORM\Column(name: 'game_slots', type: Types::JSON)]
        private array $gameSlots = [],
        #[ORM\Column(name: 'submitted_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $submittedAt = null,
        /** Story 4.23: when the member was reminded to confirm, so the reminder goes once. */
        #[ORM\Column(name: 'reminder_sent_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $reminderSentAt = null,
        /** Story 47.1: the member lets the ArchiLAN broadcast show their Twitch stream during this event. */
        #[ORM\Column(name: 'broadcast_consent', type: 'boolean', options: ['default' => true])]
        private bool $broadcastConsent = true,
        /** Story 5.10: the admin who registered the member on their behalf, null for a member's own registration. */
        #[ORM\Column(name: 'created_by_admin_id', type: 'string', length: 32, nullable: true)]
        private ?string $createdByAdminId = null,
        /** Story 5.10: the admin who confirmed on the member's behalf, null when the member confirmed. */
        #[ORM\Column(name: 'confirmed_by_admin_id', type: 'string', length: 32, nullable: true)]
        private ?string $confirmedByAdminId = null,
        /** Story 53.8: when the mail of the day before went, so it goes once. */
        #[ORM\Column(name: 'eve_reminder_sent_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $eveReminderSentAt = null,
    ) {
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getEventId(): string
    {
        return $this->eventId;
    }

    public function getUserId(): string
    {
        return $this->userId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function isReserved(): bool
    {
        return self::STATUS_RESERVED === $this->status;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * @return list<array{slotId: string, gameId: string, slotOrder: int, apworldHash?: string|null, playerYaml?: string|null, needsReview?: list<string>}>
     */
    public function getGameSlots(): array
    {
        return $this->gameSlots;
    }

    /**
     * Returns the ordered list of gameIds across all slots (may contain duplicates).
     *
     * @return list<string>
     */
    public function getSelectedGameIds(): array
    {
        return array_map(static fn (array $slot): string => $slot['gameId'], $this->gameSlots);
    }

    /**
     * @param list<array{slotId: string, gameId: string, playerYaml?: string|null, apworldHash?: string|null}> $slots
     */
    public function replaceSlots(array $slots, \DateTimeImmutable $now): void
    {
        if (!$this->isReserved()) {
            throw new \DomainException('Cannot modify slots for an inactive registration.');
        }

        $reviewBySlotId = [];
        foreach ($this->gameSlots as $existing) {
            if (isset($existing['needsReview'])) {
                $reviewBySlotId[$existing['slotId']] = $existing['needsReview'];
            }
        }

        $orderedSlots = [];
        foreach ($slots as $idx => $slot) {
            $entry = [
                'slotId' => $slot['slotId'],
                'gameId' => $slot['gameId'],
                'slotOrder' => $idx + 1,
            ];
            if (array_key_exists('playerYaml', $slot)) {
                $entry['playerYaml'] = $slot['playerYaml'];
            }
            if (array_key_exists('apworldHash', $slot)) {
                $entry['apworldHash'] = $slot['apworldHash'];
            }
            // Reordering the selection is not reviewing the slot (story 38.7).
            if (isset($reviewBySlotId[$slot['slotId']])) {
                $entry['needsReview'] = $reviewBySlotId[$slot['slotId']];
            }

            $orderedSlots[] = $entry;
        }

        $this->gameSlots = $orderedSlots;
        $this->updatedAt = $now;
    }

    public function submitSlotPlayerYaml(string $slotId, string $playerYaml, string $apworldHash, \DateTimeImmutable $now): void
    {
        if (!$this->isReserved()) {
            throw new \DomainException('Cannot set slot YAML for an inactive registration.');
        }

        foreach ($this->gameSlots as &$slot) {
            if ($slot['slotId'] === $slotId) {
                $slot['playerYaml'] = $playerYaml;
                $slot['apworldHash'] = $apworldHash;
                // Saving the slot is reviewing it (story 38.7).
                unset($slot['needsReview']);
                $this->updatedAt = $now;

                return;
            }
        }

        throw new \DomainException(sprintf('Slot "%s" not found in registration.', $slotId));
    }

    /**
     * The slot follows its game to a new apworld before the event is generated (story 38.7). Same
     * contract as the personal-run participant: `$playerYaml` replaces the YAML only when the player never
     * touched it, `$reviewReasons` marks the slot for review until the next save.
     *
     * @param list<string> $reviewReasons
     */
    public function upgradeSlotApworld(string $slotId, string $apworldHash, ?string $playerYaml, array $reviewReasons, \DateTimeImmutable $now): void
    {
        foreach ($this->gameSlots as &$slot) {
            if ($slot['slotId'] !== $slotId) {
                continue;
            }
            $slot['apworldHash'] = $apworldHash;
            if (null !== $playerYaml) {
                $slot['playerYaml'] = $playerYaml;
            }
            if ([] === $reviewReasons) {
                unset($slot['needsReview']);
            } else {
                $slot['needsReview'] = $reviewReasons;
            }
            $this->updatedAt = $now;

            return;
        }

        throw new \DomainException(sprintf('Slot "%s" not found in registration.', $slotId));
    }

    /**
     * @return array{slotId: string, gameId: string, slotOrder: int, apworldHash?: string|null, playerYaml?: string|null, needsReview?: list<string>}|null
     */
    public function getSlot(string $slotId): ?array
    {
        foreach ($this->gameSlots as $slot) {
            if ($slot['slotId'] === $slotId) {
                return $slot;
            }
        }

        return null;
    }

    public function getSubmittedAt(): ?\DateTimeImmutable
    {
        return $this->submittedAt;
    }

    /** Story 4.23: reserved, never confirmed, the seat is held without access to the event's game. */
    public function isAwaitingConfirmation(): bool
    {
        return $this->isReserved() && null === $this->submittedAt;
    }

    /**
     * Story 4.23: whether to remind the member now. Once, and only for a reservation already waiting when the reminder
     * time came: one made later is too close to the deadline to be nagged.
     */
    public function needsConfirmationReminder(\DateTimeImmutable $remindFrom): bool
    {
        return $this->isAwaitingConfirmation() && null === $this->reminderSentAt && $this->createdAt <= $remindFrom;
    }

    public function markReminded(\DateTimeImmutable $now): void
    {
        $this->reminderSentAt = $now;
        $this->updatedAt = $now;
    }

    /** Story 53.8: a confirmed registration not yet told the event is tomorrow. */
    public function needsEveReminder(): bool
    {
        return $this->isReserved() && null !== $this->submittedAt && null === $this->eveReminderSentAt;
    }

    public function markEveReminded(\DateTimeImmutable $now): void
    {
        $this->eveReminderSentAt = $now;
        $this->updatedAt = $now;
    }

    public function getEveReminderSentAt(): ?\DateTimeImmutable
    {
        return $this->eveReminderSentAt;
    }

    public function getReminderSentAt(): ?\DateTimeImmutable
    {
        return $this->reminderSentAt;
    }

    public function confirm(\DateTimeImmutable $now): void
    {
        if (!$this->isReserved()) {
            throw new \DomainException('Only reserved registrations can be confirmed.');
        }

        $this->submittedAt = $now;
        $this->updatedAt = $now;
    }

    public function cancel(\DateTimeImmutable $now): void
    {
        if (!$this->isReserved()) {
            throw new \DomainException('Only reserved registrations can be cancelled.');
        }

        $this->status = self::STATUS_CANCELLED;
        $this->updatedAt = $now;
    }

    /**
     * Story 4.22: a member who cancelled registers again. One row per member and event (unique index), so the cancelled
     * row starts over: reserved now, nothing chosen, not confirmed, and its place in the arrival order is the new one.
     */
    public function reactivate(\DateTimeImmutable $now): void
    {
        if (self::STATUS_CANCELLED !== $this->status) {
            throw new \DomainException('Only cancelled registrations can be reactivated.');
        }

        $this->status = self::STATUS_RESERVED;
        $this->gameSlots = [];
        $this->submittedAt = null;
        $this->reminderSentAt = null;
        $this->eveReminderSentAt = null;
        $this->broadcastConsent = true;
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    /**
     * Story 5.10: an admin puts a cancelled registration back, as it was: its games, YAML and confirmation are kept
     * (unlike {@see reactivate}, where the member starts over). Used to undo a cancellation or a release at
     * closing; the audit log keeps who did it.
     */
    public function restoreByAdmin(\DateTimeImmutable $now): void
    {
        if (self::STATUS_CANCELLED !== $this->status) {
            throw new \DomainException('Only cancelled registrations can be restored.');
        }

        $this->status = self::STATUS_RESERVED;
        $this->reminderSentAt = null;
        $this->updatedAt = $now;
    }

    /** Story 5.10: the admin confirms on the member's behalf. */
    public function confirmByAdmin(string $adminId, \DateTimeImmutable $now): void
    {
        $this->confirm($now);
        $this->confirmedByAdminId = $adminId;
    }

    public function getCreatedByAdminId(): ?string
    {
        return $this->createdByAdminId;
    }

    public function getConfirmedByAdminId(): ?string
    {
        return $this->confirmedByAdminId;
    }

    /** Story 47.1: on by default (a Twitch link in the profile is enough), the member alone opts out, only on a confirmed seat. */
    public function chooseBroadcastConsent(bool $consent, \DateTimeImmutable $now): void
    {
        if (!$this->isReserved()) {
            throw new \DomainException('Only reserved registrations can change their broadcast consent.');
        }

        $this->broadcastConsent = $consent;
        $this->updatedAt = $now;
    }

    public function hasBroadcastConsent(): bool
    {
        return $this->broadcastConsent;
    }
}
