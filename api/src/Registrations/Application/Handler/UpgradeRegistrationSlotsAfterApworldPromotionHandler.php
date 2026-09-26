<?php

declare(strict_types=1);

namespace App\Registrations\Application\Handler;

use App\Community\Application\Support\Notifier;
use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\GameSelection\Application\Message\ApworldPromoted;
use App\GameSelection\Application\Service\PromotedSlotYaml;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Brings the slots of event registrations along when their game switches apworld (story 38.7), as long
 * as the event has not been generated: once a session exists, the slots are frozen as before.
 *
 * Same rules as the personal runs ({@see PromotedSlotYaml}), with one difference: a slot without YAML
 * stays without YAML, the default being resolved at generation. There is no per-slot test generation on
 * the event side; the player whose YAML no longer holds is told.
 *
 * One unit of work per event: an event that fails is logged and the others go on.
 */
#[AsMessageHandler]
final readonly class UpgradeRegistrationSlotsAfterApworldPromotionHandler
{
    public const string NOTIFICATION_TYPE = 'slot_yaml_needs_review';

    public function __construct(
        private EventRepositoryInterface $events,
        private SessionRepositoryInterface $sessions,
        private RegistrationRepositoryInterface $registrations,
        private GameRepositoryInterface $games,
        private Notifier $notifier,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ApworldPromoted $promotion): void
    {
        $game = $this->games->findById($promotion->gameId);
        if (!$game instanceof Game) {
            return;
        }
        $plan = PromotedSlotYaml::for($promotion, $game);

        foreach ($this->events->findByStatuses([Event::STATUS_DRAFT, Event::STATUS_PUBLISHED]) as $event) {
            try {
                if ([] !== $this->sessions->findByEventId($event->getId())) {
                    continue;
                }
                $this->upgradeEvent($event, $game, $plan);
            } catch (\Throwable $e) {
                $this->logger->error('registration.slot_upgrade_failed', ['eventId' => $event->getId(), 'gameId' => $promotion->gameId, 'error' => $e->getMessage()]);
            }
        }
    }

    private function upgradeEvent(Event $event, Game $game, PromotedSlotYaml $plan): void
    {
        // Two steps (story 38.7 review): decide every slot of the event first, touching nothing, then apply.
        $upgrades = [];
        foreach ($this->registrations->findBy(['eventId' => $event->getId(), 'status' => Registration::STATUS_RESERVED]) as $registration) {
            foreach ($registration->getGameSlots() as $slot) {
                if (!$plan->concerns($slot['gameId'], $slot['apworldHash'] ?? null)) {
                    continue;
                }
                $playerYaml = $slot['playerYaml'] ?? null;
                // A slot without YAML stays without: the default is resolved at generation.
                $decision = null === $playerYaml || '' === trim($playerYaml) ? null : $plan->decide($playerYaml);
                $upgrades[] = [$registration, $slot['slotId'], $decision];
            }
        }
        if ([] === $upgrades) {
            return;
        }

        $notifications = [];
        $now = $this->clock->now();
        foreach ($upgrades as [$registration, $slotId, $decision]) {
            $registration->upgradeSlotApworld($slotId, $plan->promotion->newHash, $decision?->replacementYaml, $decision->reviewReasons ?? [], $now);
            if (null !== $decision && $decision->needsReview()) {
                $notifications[] = [$registration->getUserId(), [
                    'eventId' => $event->getId(),
                    'eventTitle' => $event->getTitle(),
                    'registrationId' => $registration->getId(),
                    'gameId' => $game->getId(),
                    'gameName' => $game->getName(),
                    'slotId' => $slotId,
                    'reasons' => $decision->reviewReasons,
                ]];
            }
        }
        $upgraded = \count($upgrades);

        // The event's unit of work, then what it triggers.
        $this->registrations->flush();
        foreach ($notifications as [$userId, $payload]) {
            $this->notifier->notify($userId, self::NOTIFICATION_TYPE, $payload);
        }

        $this->logger->info('registration.slots_upgraded', [
            'eventId' => $event->getId(),
            'gameId' => $game->getId(),
            'upgraded' => $upgraded,
            'needsReview' => \count($notifications),
        ]);
    }
}
