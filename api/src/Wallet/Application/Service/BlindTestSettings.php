<?php

declare(strict_types=1);

namespace App\Wallet\Application\Service;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use App\Wallet\Application\Support\BlindTestLink;
use App\Wallet\Domain\Entity\BlindTestSetting;
use App\Wallet\Domain\Repository\BlindTestSettingRepositoryInterface;

/**
 * An event's blind test, admin side (story 41.40): read and tuned from the event's pelles page, with the way to the
 * app's console (story 41.46).
 */
final readonly class BlindTestSettings
{
    public const int MAX_PELLES_PER_SOURCE = 100;
    public const int MAX_CAP_PER_PLAYER = 10000;

    public function __construct(
        private BlindTestSettingRepositoryInterface $settings,
        private EventRepositoryInterface $events,
        private BlindTestLink $link,
    ) {
    }

    /**
     * @return array{enabled: bool, pellesPerSource: int, capPerPlayer: int, pellesForUnregistered: bool, consoleUrl: string|null}
     *
     * @throws NotFoundException when the event does not exist
     */
    public function of(string $eventId): array
    {
        $this->requireEvent($eventId);

        return $this->view($this->settings->find($eventId) ?? BlindTestSetting::off($eventId));
    }

    /**
     * @param bool|null $pellesForUnregistered null keeps the current choice
     *
     * @return array{enabled: bool, pellesPerSource: int, capPerPlayer: int, pellesForUnregistered: bool, consoleUrl: string|null}
     *
     * @throws NotFoundException   when the event does not exist
     * @throws ValidationException when an amount is out of bounds
     */
    public function change(string $eventId, bool $enabled, int $pellesPerSource, int $capPerPlayer, ?bool $pellesForUnregistered = null): array
    {
        $this->requireEvent($eventId);
        $errors = [];
        if ($pellesPerSource < 1 || $pellesPerSource > self::MAX_PELLES_PER_SOURCE) {
            $errors['pellesPerSource'] = [sprintf('De 1 à %d pelles.', self::MAX_PELLES_PER_SOURCE)];
        }
        if ($capPerPlayer < 1 || $capPerPlayer > self::MAX_CAP_PER_PLAYER) {
            $errors['capPerPlayer'] = [sprintf('De 1 à %d pelles.', self::MAX_CAP_PER_PLAYER)];
        }
        if ([] !== $errors) {
            throw new ValidationException('Réglages du blind test invalides.', $errors, 'invalid_blind_test_settings');
        }

        $setting = $this->settings->find($eventId) ?? BlindTestSetting::off($eventId);
        $setting->change($enabled, $pellesPerSource, $capPerPlayer, $pellesForUnregistered ?? $setting->isPellesForUnregistered());
        $this->settings->save($setting);

        return $this->view($setting);
    }

    private function requireEvent(string $eventId): void
    {
        if (!$this->events->findById($eventId) instanceof Event) {
            throw new NotFoundException('Événement introuvable.', 'event_not_found');
        }
    }

    /** @return array{enabled: bool, pellesPerSource: int, capPerPlayer: int, pellesForUnregistered: bool, consoleUrl: string|null} */
    private function view(BlindTestSetting $setting): array
    {
        return [
            'enabled' => $setting->isEnabled(),
            'pellesPerSource' => $setting->getPellesPerSource(),
            'capPerPlayer' => $setting->getCapPerPlayer(),
            'pellesForUnregistered' => $setting->isPellesForUnregistered(),
            'consoleUrl' => $this->link->consoleUrl($setting->getEventId()),
        ];
    }
}
