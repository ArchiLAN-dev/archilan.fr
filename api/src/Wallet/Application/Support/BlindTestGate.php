<?php

declare(strict_types=1);

namespace App\Wallet\Application\Support;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Wallet\Domain\Entity\BlindTestSetting;
use App\Wallet\Domain\Repository\BlindTestSettingRepositoryInterface;

/**
 * Whether the blind test of an event is open, now (story 41.40): the same rule for the participation link and for each
 * credit, so switching the blind test off or ending the event stops both at once. Any member plays, registered to the
 * event or not (story 41.54).
 */
final readonly class BlindTestGate
{
    public function __construct(
        private BlindTestLink $link,
        private EventRepositoryInterface $events,
        private BlindTestSettingRepositoryInterface $settings,
    ) {
    }

    /**
     * @throws ServiceUnavailableException when the site has no secret or no app address
     * @throws NotFoundException           when the event does not exist
     * @throws ForbiddenException          when the blind test is off or the event is not running
     */
    public function open(string $eventId, \DateTimeImmutable $now): BlindTestOpening
    {
        if (!$this->link->isConfigured()) {
            throw new ServiceUnavailableException('Le blind test n\'est pas configuré sur le site.', 'blind_test_unconfigured');
        }
        $event = $this->events->findById($eventId);
        if (!$event instanceof Event) {
            throw new NotFoundException('Événement introuvable.', 'event_not_found');
        }
        $setting = $this->settings->find($eventId);
        if (!$setting instanceof BlindTestSetting || !$setting->isEnabled()) {
            throw new ForbiddenException('Le blind test n\'est pas ouvert pour cet événement.', 'blind_test_disabled');
        }
        if ($now < $event->getStartsAt() || $now >= $event->getEndsAt()) {
            throw new ForbiddenException('Le blind test se joue pendant l\'événement.', 'event_not_running');
        }

        return new BlindTestOpening($setting->getPellesPerSource(), $setting->getCapPerPlayer(), $event->getEndsAt(), $setting->isPellesForUnregistered());
    }
}
