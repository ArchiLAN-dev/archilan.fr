<?php

declare(strict_types=1);

namespace App\Events\Application\Command;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Identity\Application\Support\ValidationErrors;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

final readonly class AdminEventRecap
{
    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{found: bool, errors: array<string, list<string>>}
     */
    public function attach(string $eventId, array $input): array
    {
        $event = $this->eventRepository->findById($eventId);

        if (!$event instanceof Event) {
            return ['found' => false, 'errors' => []];
        }

        $parsed = $this->parse($input);
        $errors = $this->validate($parsed);

        if ([] !== $errors) {
            return ['found' => true, 'errors' => $errors];
        }

        try {
            $event->attachRecap($parsed['vodUrl'], $parsed['recapPostSlug'], $this->clock->now());
        } catch (\DomainException) {
            return ['found' => true, 'errors' => ['status' => ["Le récap ne peut être attaché qu'à un événement terminé."]]];
        }

        $this->eventRepository->save($event);

        $this->logger->info('event.recap_attached', ['eventId' => $eventId]);

        return ['found' => true, 'errors' => []];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{vodUrl: string|null, recapPostSlug: string|null}
     */
    private function parse(array $input): array
    {
        $vodUrl = is_string($input['vodUrl'] ?? null) ? trim($input['vodUrl']) : null;
        $recapPostSlug = is_string($input['recapPostSlug'] ?? null) ? trim($input['recapPostSlug']) : null;

        return [
            'vodUrl' => ('' === $vodUrl) ? null : $vodUrl,
            'recapPostSlug' => ('' === $recapPostSlug) ? null : $recapPostSlug,
        ];
    }

    /**
     * @param array{vodUrl: string|null, recapPostSlug: string|null} $parsed
     *
     * @return array<string, list<string>>
     */
    private function validate(array $parsed): array
    {
        $errors = new ValidationErrors();

        if (null !== $parsed['vodUrl'] && false === filter_var($parsed['vodUrl'], FILTER_VALIDATE_URL)) {
            $errors->add('vodUrl', "L'URL de la VOD est invalide.");
        }

        if (null !== $parsed['recapPostSlug'] && !preg_match('/^[a-z0-9][a-z0-9-]*$/', $parsed['recapPostSlug'])) {
            $errors->add('recapPostSlug', 'Le slug du récap est invalide (minuscules, chiffres et tirets uniquement).');
        }

        return $errors->toArray();
    }
}
