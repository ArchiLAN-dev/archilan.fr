<?php

declare(strict_types=1);

namespace App\Wallet\Application\Service;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use App\Wallet\Application\Exception\MinimapperzUnauthorizedException;
use App\Wallet\Application\Exception\MinimapperzUnavailableException;
use App\Wallet\Application\Port\MinimapperzClientInterface;
use App\Wallet\Application\Support\MinimapperzSlug;
use App\Wallet\Domain\Entity\MinimapperzSetting;
use App\Wallet\Domain\Repository\MinimapperzSettingRepositoryInterface;
use Psr\Log\LoggerInterface;

/**
 * An event's link to Minimapperz, admin side (story 49.1): the slug and key there, what a game is worth, and a check
 * that the key opens the event. The key is written, never read back: the admin only learns that one is set and how
 * it ends.
 *
 * Story 49.3: with where the collection of the games stands.
 *
 * @phpstan-type MinimapperzCollectionView array{at: string|null, status: string|null, games: int, credited: int, until: string|null}
 * @phpstan-type MinimapperzSettingsView array{enabled: bool, slug: string|null, apiKeySet: bool, apiKeyEnd: string|null, pellesPerGame: int, pellesPerWin: int, capPerPlayer: int, collection: MinimapperzCollectionView}
 * @phpstan-type MinimapperzEventView array{slug: string, name: string, opensAt: string, closesAt: string, isOpen: bool}
 * @phpstan-type MinimapperzCheckView array{status: 'ok'|'not_configured'|'unauthorized'|'unreachable', event: MinimapperzEventView|null}
 */
final readonly class MinimapperzSettings
{
    public const int MAX_PELLES_PER_GAME = 100;
    public const int MAX_PELLES_PER_WIN = 100;
    public const int MAX_CAP_PER_PLAYER = 10000;
    public const int MAX_API_KEY_LENGTH = 200;
    private const int KEY_END_LENGTH = 3;

    public function __construct(
        private MinimapperzSettingRepositoryInterface $settings,
        private EventRepositoryInterface $events,
        private MinimapperzClientInterface $client,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return MinimapperzSettingsView
     *
     * @throws NotFoundException when the event does not exist
     */
    public function of(string $eventId): array
    {
        $this->requireEvent($eventId);

        return $this->view($this->settings->find($eventId) ?? MinimapperzSetting::off($eventId));
    }

    /**
     * @param string|null $apiKey null or empty keeps the current key
     *
     * @return MinimapperzSettingsView
     *
     * @throws NotFoundException   when the event does not exist
     * @throws ValidationException when a value is out of bounds, or the link is switched on without what it needs
     */
    public function change(
        string $eventId,
        bool $enabled,
        string $slugInput,
        ?string $apiKey,
        bool $clearApiKey,
        int $pellesPerGame,
        int $pellesPerWin,
        int $capPerPlayer,
    ): array {
        $this->requireEvent($eventId);
        $setting = $this->settings->find($eventId) ?? MinimapperzSetting::off($eventId);

        $errors = [];
        $slug = null;
        if ('' !== trim($slugInput)) {
            $slug = MinimapperzSlug::fromInput($slugInput);
            if (null === $slug) {
                $errors['slug'] = ["Colle le slug de l'event ou l'adresse de la LAN Minimapperz."];
            }
        }
        $newKey = null === $apiKey ? '' : trim($apiKey);
        if ('' !== $newKey && (mb_strlen($newKey) > self::MAX_API_KEY_LENGTH || 1 === preg_match('/\s/', $newKey))) {
            $errors['apiKey'] = [sprintf('Clé invalide : sans espace, %d caractères au plus.', self::MAX_API_KEY_LENGTH)];
        }
        if ($pellesPerGame < 0 || $pellesPerGame > self::MAX_PELLES_PER_GAME) {
            $errors['pellesPerGame'] = [sprintf('De 0 à %d pelles.', self::MAX_PELLES_PER_GAME)];
        }
        if ($pellesPerWin < 0 || $pellesPerWin > self::MAX_PELLES_PER_WIN) {
            $errors['pellesPerWin'] = [sprintf('De 0 à %d pelles.', self::MAX_PELLES_PER_WIN)];
        }
        if ($capPerPlayer < 1 || $capPerPlayer > self::MAX_CAP_PER_PLAYER) {
            $errors['capPerPlayer'] = [sprintf('De 1 à %d pelles.', self::MAX_CAP_PER_PLAYER)];
        }
        if ($enabled) {
            $keyAfter = '' !== $newKey ? $newKey : ($clearApiKey ? null : $setting->getApiKey());
            if (null === $slug && !isset($errors['slug'])) {
                $errors['slug'] = ['Le slug est requis pour activer Minimapperz.'];
            }
            if (null === $keyAfter && !isset($errors['apiKey'])) {
                $errors['apiKey'] = ['La clé API est requise pour activer Minimapperz.'];
            }
            if (0 === $pellesPerGame + $pellesPerWin && !isset($errors['pellesPerGame']) && !isset($errors['pellesPerWin'])) {
                $errors['pellesPerGame'] = ['Une partie ou une victoire doit rapporter au moins 1 pelle.'];
            }
        }
        if ([] !== $errors) {
            throw new ValidationException('Réglages Minimapperz invalides.', $errors, 'invalid_minimapperz_settings');
        }

        $setting->change($enabled, $slug, $pellesPerGame, $pellesPerWin, $capPerPlayer);
        if ('' !== $newKey) {
            $setting->replaceApiKey($newKey);
        } elseif ($clearApiKey) {
            $setting->clearApiKey();
        }
        $this->settings->save($setting);

        return $this->view($setting);
    }

    /**
     * Asks Minimapperz for the event with the key on file. Reads only: the outcome is told, nothing is stored.
     *
     * @return MinimapperzCheckView
     *
     * @throws NotFoundException when the event does not exist
     */
    public function checkConnection(string $eventId): array
    {
        $this->requireEvent($eventId);
        $setting = $this->settings->find($eventId);
        $slug = $setting?->getSlug();
        $apiKey = $setting?->getApiKey();
        if (null === $slug || null === $apiKey) {
            return ['status' => 'not_configured', 'event' => null];
        }

        try {
            $event = $this->client->event($slug, $apiKey);
        } catch (MinimapperzUnauthorizedException) {
            return ['status' => 'unauthorized', 'event' => null];
        } catch (MinimapperzUnavailableException $e) {
            $this->logger->warning('Minimapperz connection check failed for event {eventId}: {reason}', ['eventId' => $eventId, 'reason' => $e->getMessage()]);

            return ['status' => 'unreachable', 'event' => null];
        }

        return ['status' => 'ok', 'event' => [
            'slug' => $event->slug,
            'name' => $event->name,
            'opensAt' => $event->opensAt->format(\DateTimeInterface::ATOM),
            'closesAt' => $event->closesAt->format(\DateTimeInterface::ATOM),
            'isOpen' => $event->isOpen,
        ]];
    }

    private function requireEvent(string $eventId): void
    {
        if (!$this->events->findById($eventId) instanceof Event) {
            throw new NotFoundException('Événement introuvable.', 'event_not_found');
        }
    }

    /** @return MinimapperzSettingsView */
    private function view(MinimapperzSetting $setting): array
    {
        $apiKey = $setting->getApiKey();

        return [
            'enabled' => $setting->isEnabled(),
            'slug' => $setting->getSlug(),
            'apiKeySet' => null !== $apiKey,
            'apiKeyEnd' => null === $apiKey ? null : mb_substr($apiKey, -self::KEY_END_LENGTH),
            'pellesPerGame' => $setting->getPellesPerGame(),
            'pellesPerWin' => $setting->getPellesPerWin(),
            'capPerPlayer' => $setting->getCapPerPlayer(),
            'collection' => [
                'at' => self::atom($setting->getLastCollectAt()),
                'status' => $setting->getLastCollectStatus(),
                'games' => $setting->getLastCollectGames(),
                'credited' => $setting->getLastCollectCredited(),
                'until' => self::atom($setting->getCollectedUntil()),
            ],
        ];
    }

    private static function atom(?\DateTimeImmutable $date): ?string
    {
        return $date?->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }
}
