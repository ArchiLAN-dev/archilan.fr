<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Community\Application\Support\Notifier;
use App\Community\Domain\Entity\Notification;
use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use App\Wallet\Application\Query\EventPellesQueryInterface;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use Psr\Clock\ClockInterface;

/**
 * An admin hands out event pelles to the registrants of an event (story 41.2): all of them, or a selection.
 *
 * Each recipient gets their own movement through {@see RecordPelleMovement}, keyed by the request id the form
 * sends, so a double submit credits nobody twice. A banned or erased registrant is skipped - the ledger refuses
 * them - without failing the others.
 */
final readonly class DistributeEventPelles
{
    public const int MAX_AMOUNT = 1000;
    private const int MAX_REQUEST_ID_LENGTH = 40;

    public function __construct(
        private RecordPelleMovement $record,
        private EventRepositoryInterface $events,
        private EventPellesQueryInterface $query,
        private Notifier $notifier,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<string>|null $userIds the selection, or null for every active registrant
     *
     * @throws NotFoundException   when the event does not exist
     * @throws ValidationException when the request is malformed, the event has ended or the selection holds
     *                             someone who is not registered
     */
    public function distribute(string $adminId, string $eventId, int $amount, string $label, string $requestId, ?array $userIds): EventPelleDistribution
    {
        $event = $this->events->findById($eventId);
        if (!$event instanceof Event) {
            throw new NotFoundException('Événement introuvable.', 'event_not_found');
        }
        if ($event->getEndsAt() <= $this->clock->now()) {
            throw new ValidationException('Cet événement est terminé : ses pelles ont expiré.', [], 'event_ended');
        }
        if ($amount < 1 || $amount > self::MAX_AMOUNT) {
            throw new ValidationException(sprintf('Le montant va de 1 à %d pelles par membre.', self::MAX_AMOUNT), ['amount' => ['Montant hors bornes.']], 'invalid_amount');
        }
        $label = trim($label);
        if ('' === $label || mb_strlen($label) > PelleMovement::LABEL_MAX_LENGTH) {
            throw new ValidationException('Le libellé est obligatoire (200 caractères au plus).', ['label' => ['Libellé obligatoire.']], 'invalid_label');
        }
        if ('' === $requestId || \strlen($requestId) > self::MAX_REQUEST_ID_LENGTH) {
            throw new ValidationException('Identifiant de requête manquant.', [], 'invalid_request_id');
        }

        $registrants = $this->query->activeRegistrantIds($eventId);
        if (null !== $userIds) {
            $userIds = array_values(array_unique($userIds));
            if ([] === $userIds) {
                throw new ValidationException('Choisis au moins un membre.', ['userIds' => ['Sélection vide.']], 'empty_selection');
            }
            if ([] !== array_diff($userIds, $registrants)) {
                throw new ValidationException('La sélection contient un compte qui n\'est pas inscrit.', ['userIds' => ['Compte non inscrit.']], 'not_registered');
            }
        }

        $credited = 0;
        $skipped = 0;
        $already = 0;
        foreach ($userIds ?? $registrants as $userId) {
            try {
                $recorded = $this->record->record(new RecordPelleMovementInput(
                    $userId,
                    $amount,
                    PelleKind::Event,
                    $eventId,
                    PelleReason::EventDistribution,
                    $label,
                    $adminId,
                    sprintf('event-distribution:%s:%s', $requestId, $userId),
                ));
            } catch (ForbiddenException|NotFoundException) {
                ++$skipped;
                continue;
            }

            if ($recorded->alreadyRecorded) {
                ++$already;
                continue;
            }
            ++$credited;
            // The movement is committed: telling the member is a side effect after it.
            $this->notifier->notify($userId, Notification::TYPE_PELLES_ADJUSTED, [
                'amount' => $amount,
                'kind' => PelleKind::Event->value,
                'reason' => $label,
                'eventTitle' => $event->getTitle(),
            ]);
        }

        return new EventPelleDistribution($credited, $skipped, $already);
    }
}
