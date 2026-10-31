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

/** Story 45.3: the admin picks the partners thanked on an event, each with an optional mention. */
final readonly class ChooseEventPartners
{
    private const array MESSAGES = [
        'event_partners_too_many' => 'Un événement compte au plus 5 partenaires.',
        'event_partner_id_invalid' => 'Un partenaire choisi est invalide.',
        'event_partner_duplicate' => 'Un partenaire est choisi deux fois.',
        'event_partner_mention_too_long' => 'Une mention fait au plus 120 caractères.',
    ];

    public function __construct(
        private EventRepositoryInterface $eventRepository,
        private LoggerInterface $logger,
        private ClockInterface $clock,
        private PublicPagesRevalidation $publicPages,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @throws NotFoundException   when the event does not exist
     * @throws ValidationException when the partner list is invalid
     */
    public function choose(string $eventId, array $input): void
    {
        $event = $this->eventRepository->findById($eventId);

        if (!$event instanceof Event) {
            throw new NotFoundException('Événement introuvable.');
        }

        $partners = $this->parse($input);
        if (null === $partners) {
            throw new ValidationException('Les partenaires de l\'événement sont invalides.', ['partners' => ['La liste des partenaires est invalide.']]);
        }

        try {
            $event->choosePartners($partners, $this->clock->now());
        } catch (\DomainException $exception) {
            $message = self::MESSAGES[$exception->getMessage()] ?? 'La liste des partenaires est invalide.';
            throw new ValidationException('Les partenaires de l\'événement sont invalides.', ['partners' => [$message]]);
        }

        $this->eventRepository->save($event);
        $this->publicPages->eventChanged($eventId);

        $this->logger->info('event.partners_chosen', ['eventId' => $eventId, 'count' => count($partners)]);
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return list<array{partnerId: string, mention: string|null}>|null null when the shape is wrong
     */
    private function parse(array $input): ?array
    {
        $raw = $input['partners'] ?? null;
        if (!is_array($raw) || !array_is_list($raw)) {
            return null;
        }

        $partners = [];
        foreach ($raw as $item) {
            if (!is_array($item) || !is_string($item['partnerId'] ?? null)) {
                return null;
            }
            $mention = $item['mention'] ?? null;
            if (null !== $mention && !is_string($mention)) {
                return null;
            }
            $partners[] = ['partnerId' => $item['partnerId'], 'mention' => $mention];
        }

        return $partners;
    }
}
