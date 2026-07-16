<?php

declare(strict_types=1);

namespace App\Events\Application\Command;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Identity\Application\Support\ValidationErrors;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
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
     * @throws NotFoundException   when the event does not exist
     * @throws ValidationException when the recap data is invalid
     */
    public function attach(string $eventId, array $input): void
    {
        $event = $this->eventRepository->findById($eventId);

        if (!$event instanceof Event) {
            throw new NotFoundException('Événement introuvable.');
        }

        $parsed = $this->parse($input);
        $errors = $this->validate($parsed);

        if ([] !== $errors) {
            throw new ValidationException('Les données de récap sont invalides.', $errors);
        }

        try {
            $event->attachRecap($parsed['vodUrl'], $parsed['recapPostSlug'], $this->clock->now());
        } catch (\DomainException) {
            throw new ValidationException('Les données de récap sont invalides.', ['status' => ["Le récap ne peut être attaché qu'à un événement terminé."]]);
        }

        $this->eventRepository->save($event);

        $this->logger->info('event.recap_attached', ['eventId' => $eventId]);
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

        if (null !== $parsed['recapPostSlug'] && 1 !== preg_match('/^[a-z0-9][a-z0-9-]*$/', $parsed['recapPostSlug'])) {
            $errors->add('recapPostSlug', 'Le slug du récap est invalide (minuscules, chiffres et tirets uniquement).');
        }

        return $errors->toArray();
    }
}
