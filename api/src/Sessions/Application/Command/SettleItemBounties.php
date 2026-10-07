<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Community\Application\Support\Notifier;
use App\Community\Domain\Entity\Notification;
use App\Sessions\Application\Support\SlotOwnerResolver;
use App\Sessions\Domain\Entity\ItemBounty;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Sessions\Domain\Repository\ItemBountyRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Wallet\Application\Command\RecordPelleMovement;
use App\Wallet\Application\Command\RecordPelleMovementInput;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * An item reached a slot: if that slot offered a bounty for it, the bounty is settled (story 41.4).
 *
 * It is paid to the owner of the sending slot, minus the commission, only when someone actually found the item.
 * Archipelago tells a release or a collect apart from a find nowhere in the feed, so an item does not count when
 * either slot released or collected, or had already reached its goal (a goal is followed by the release). Nor
 * does an item sent to oneself, by a slot nobody on the site owns (the `Bridge` observer), or by the poster's own
 * slot. The item came all the same, so the bounty is then refunded to its poster.
 */
final readonly class SettleItemBounties
{
    public function __construct(
        private ItemBountyRepositoryInterface $bounties,
        private SessionSlotRepositoryInterface $slots,
        private SlotOwnerResolver $owners,
        private RecordPelleMovement $record,
        private Notifier $notifier,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<array-key, mixed> $event a feed event as the bridge pushes it
     */
    public function onFeedEvent(string $sessionId, array $event): void
    {
        if ('item-received' !== ($event['type'] ?? null)) {
            return;
        }
        $itemName = self::name($event, 'item');
        $receiverName = self::name($event, 'receiver');
        $senderName = self::name($event, 'sender');
        if (null === $itemName || null === $receiverName) {
            return;
        }

        $bounty = $this->bounties->findOpenFor($sessionId, $receiverName, $itemName);
        if (!$bounty instanceof ItemBounty) {
            return;
        }

        $now = $this->clock->now();
        $winner = $this->winner($bounty, $senderName, $receiverName, $now);
        if (null !== $winner && $this->pay($bounty, $winner, $now)) {
            return;
        }
        $this->refund($bounty, $now);
    }

    private function winner(ItemBounty $bounty, ?string $senderName, string $receiverName, \DateTimeImmutable $now): ?string
    {
        if (null === $senderName || $senderName === $receiverName) {
            return null;
        }
        $sender = $this->slots->findBySessionAndSlotName($bounty->getSessionId(), $senderName);
        $receiver = $this->slots->findBySessionAndSlotName($bounty->getSessionId(), $receiverName);
        if (!$sender instanceof SessionSlot || self::releasedOrDone($sender, $now) || ($receiver instanceof SessionSlot && self::releasedOrDone($receiver, $now))) {
            return null;
        }
        $owner = $this->owners->ownerOf($sender);

        return null === $owner || $owner === $bounty->getPosterId() ? null : $owner;
    }

    private static function releasedOrDone(SessionSlot $slot, \DateTimeImmutable $now): bool
    {
        $goal = $slot->getGoalReachedAt();

        return $slot->isWasReleased() || (null !== $goal && $goal <= $now);
    }

    private function pay(ItemBounty $bounty, string $winnerId, \DateTimeImmutable $now): bool
    {
        $reward = $bounty->reward();
        try {
            $this->record->record(
                new RecordPelleMovementInput(
                    $winnerId, $reward, PelleKind::Gold, null, PelleReason::BountyReward,
                    sprintf('Prime gagnée : %s envoyé à %s', $bounty->getItemName(), $bounty->getSlotName()), null,
                    sprintf('bounty-reward:%s', $bounty->getId()),
                ),
                // Settled with the payment, in its transaction: a refused payment leaves the bounty open.
                function () use ($bounty, $winnerId, $now): void {
                    $bounty->pay($winnerId, $now);
                    $this->bounties->save($bounty);
                },
            );
        } catch (ForbiddenException|NotFoundException $e) {
            // A banned or erased winner earns nothing: the bounty goes back to its poster instead.
            $this->logger->info('sessions.bounty.winner_refused', ['bountyId' => $bounty->getId(), 'reason' => $e->getMessage()]);

            return false;
        }

        $this->notifier->notify($winnerId, Notification::TYPE_PELLES_ADJUSTED, [
            'amount' => $reward,
            'kind' => PelleKind::Gold->value,
            'reason' => sprintf('prime pour %s', $bounty->getItemName()),
        ]);

        return true;
    }

    private function refund(ItemBounty $bounty, \DateTimeImmutable $now): void
    {
        $this->record->record(
            new RecordPelleMovementInput(
                $bounty->getPosterId(), $bounty->getAmount(), PelleKind::Gold, null, PelleReason::BountyRefund,
                sprintf('Prime rendue : %s est arrivé', $bounty->getItemName()), null,
                sprintf('bounty-refund:%s', $bounty->getId()),
                byAdmin: true,
            ),
            function () use ($bounty, $now): void {
                $bounty->refund($now);
                $this->bounties->save($bounty);
            },
        );
        $this->notifier->notify($bounty->getPosterId(), Notification::TYPE_PELLES_ADJUSTED, [
            'amount' => $bounty->getAmount(),
            'kind' => PelleKind::Gold->value,
            'reason' => sprintf('prime rendue, %s est arrivé', $bounty->getItemName()),
        ]);
    }

    /**
     * @param array<array-key, mixed> $event
     */
    private static function name(array $event, string $key): ?string
    {
        $part = $event[$key] ?? null;
        $name = is_array($part) ? ($part['name'] ?? null) : null;

        return is_string($name) && '' !== $name ? $name : null;
    }
}
