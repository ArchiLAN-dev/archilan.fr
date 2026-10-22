<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\SessionConfig\Application\Service\SessionConfigResolver;
use App\SessionConfig\Domain\Enum\SessionType;
use App\SessionConfig\Domain\ValueObject\SessionServerConfig;
use App\Sessions\Application\Support\RunSlotPlayers;
use App\Sessions\Domain\Entity\SessionSlot;
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
 * A private run's bronze pelles, earned by playing it (story 41.37): every player of a slot - its owner and its
 * co-players, each on their own balance - earns the run's amount per check of that slot and at its goal.
 *
 * A check is an `item-received` of the feed sent by the slot: the location it was found at belongs to the sender's
 * world. Only a check actually played pays, under the rule the bounties already follow (story 41.4): nothing when
 * either slot released or collected, or had reached its goal (a goal is followed by the release), and nothing for a
 * slot nobody on the site plays. An item found for oneself is a check played: it pays.
 *
 * The keys name the location (`bronze-check:{run}:{slot}:{location}:{member}`), so a feed event received twice -
 * a replay, a restart - pays once.
 *
 * Story 41.39: an ended run pays nothing more, its bronze has burned; and the receiver's goal alone no longer stops
 * the sender's check from paying, only the receiver's release or collect does.
 */
final readonly class CreditRunBronze
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private SessionSlotRepositoryInterface $slots,
        private RunSlotPlayers $players,
        private SessionConfigResolver $configResolver,
        private RecordPelleMovement $record,
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
        $senderName = self::part($event, 'sender', 'name');
        $locationId = self::part($event, 'location', 'id');
        if (!\is_string($senderName) || '' === $senderName || !\is_int($locationId)) {
            return;
        }

        $run = $this->runs->findBySessionId($sessionId);
        if (!$run instanceof Run || $run->isTerminal()) {
            return;
        }
        $amount = $this->terms($run)->bronzePerCheck;
        if ($amount <= 0) {
            return;
        }

        $now = $this->clock->now();
        $sender = $this->slots->findBySessionAndSlotName($sessionId, $senderName);
        if (!$sender instanceof SessionSlot || $sender->stoppedFinding($now)) {
            return;
        }
        $receiverName = self::part($event, 'receiver', 'name');
        $receiver = \is_string($receiverName) && '' !== $receiverName ? $this->slots->findBySessionAndSlotName($sessionId, $receiverName) : null;
        if ($receiver instanceof SessionSlot && $receiver->handedOverBy($now)) {
            return;
        }

        $itemName = self::part($event, 'item', 'name');
        $label = \is_string($itemName) && '' !== $itemName ? sprintf('Check de %s : %s', $sender->getSlotName(), $itemName) : sprintf('Check de %s', $sender->getSlotName());
        foreach ($this->players->ofSlot($sender) as $userId) {
            $this->credit($run, $userId, $amount, PelleReason::BronzeCheck, $label, sprintf('bronze-check:%s:%d:%d:%s', $run->getId(), $sender->getSlotOrder(), $locationId, $userId));
        }
    }

    /** The slot reached its goal: its players earn the run's goal amount, once. */
    public function onGoal(string $sessionId, string $slotName): void
    {
        $run = $this->runs->findBySessionId($sessionId);
        if (!$run instanceof Run || $run->isTerminal()) {
            return;
        }
        $amount = $this->terms($run)->bronzePerGoal;
        $slot = $this->slots->findBySessionAndSlotName($sessionId, $slotName);
        if ($amount <= 0 || !$slot instanceof SessionSlot) {
            return;
        }

        foreach ($this->players->ofSlot($slot) as $userId) {
            $this->credit($run, $userId, $amount, PelleReason::BronzeGoal, sprintf('Objectif de %s', $slot->getSlotName()), sprintf('bronze-goal:%s:%d:%s', $run->getId(), $slot->getSlotOrder(), $userId));
        }
    }

    private function terms(Run $run): SessionServerConfig
    {
        return $this->configResolver->resolve(SessionType::Private, $run->getId())->server;
    }

    private function credit(Run $run, string $userId, int $amount, PelleReason $reason, string $label, string $key): void
    {
        try {
            $this->record->record(new RecordPelleMovementInput($userId, $amount, PelleKind::Bronze, null, $reason, $label, null, $key, runId: $run->getId()));
        } catch (ForbiddenException|NotFoundException $e) {
            // A banned or deleted player earns nothing; the others of the slot still do.
            $this->logger->info('sessions.bronze.credit_refused', ['runId' => $run->getId(), 'userId' => $userId, 'reason' => $e->getMessage()]);
        }
    }

    /**
     * @param array<array-key, mixed> $event
     */
    private static function part(array $event, string $key, string $field): mixed
    {
        $part = $event[$key] ?? null;

        return \is_array($part) ? ($part[$field] ?? null) : null;
    }
}
