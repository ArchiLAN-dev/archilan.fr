<?php

declare(strict_types=1);

namespace App\Events\Application\Command;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use App\Shared\Application\Support\PublicPagesRevalidation;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Story 3.15: the admin picks the event featured on the home page, or removes it. One event at most: featuring one
 * removes the previous pick in the same flush. Without a pick, the home page keeps its automatic rule.
 */
final readonly class FeatureEventOnHome
{
    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private LoggerInterface $logger,
        private ClockInterface $clock,
        private PublicPagesRevalidation $publicPages,
    ) {
    }

    /**
     * @throws NotFoundException   when the event does not exist
     * @throws ValidationException when a draft is featured
     */
    public function choose(string $eventId, bool $featured): void
    {
        $event = $this->eventRepository->findById($eventId);

        if (!$event instanceof Event) {
            throw new NotFoundException('Événement introuvable.');
        }

        $now = $this->clock->now();
        $changed = [$eventId];

        if (!$featured) {
            $event->removeFromHome($now);
        } else {
            try {
                $event->featureOnHome($now);
            } catch (\DomainException) {
                throw new ValidationException('Cet événement ne peut pas être mis en avant.', ['featured' => ['Publiez l\'événement avant de le mettre en avant.']]);
            }

            foreach ($this->eventRepository->findFeaturedOnHome() as $previous) {
                if ($previous->getId() !== $eventId) {
                    $previous->removeFromHome($now);
                    $changed[] = $previous->getId();
                }
            }
        }

        // One flush writes the pick and the removal of the previous one together.
        $this->eventRepository->save($event);
        foreach ($changed as $changedId) {
            $this->publicPages->eventChanged($changedId);
        }

        $this->logger->info('event.featured_on_home', ['eventId' => $eventId, 'featured' => $featured]);
    }
}
