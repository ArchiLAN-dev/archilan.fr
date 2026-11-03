<?php

declare(strict_types=1);

namespace App\Wallet\Application\Service;

use App\Events\Domain\Entity\Event;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use App\Wallet\Application\Query\MinimapperzCodesQueryInterface;
use App\Wallet\Application\Support\MinimapperzCodeList;
use App\Wallet\Domain\Repository\MinimapperzCodeRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * The Minimapperz codes of an event, admin side (story 49.2): the list Minimapperz gave, imported by pasting it, then
 * handed to the registrants - each on opening the event's page, or all at once from here. A code taken back is free
 * again; a cancelled registration keeps its code (it may already have been typed on Minimapperz).
 *
 * @phpstan-import-type MinimapperzCodeRow from MinimapperzCodesQueryInterface
 *
 * @phpstan-type MinimapperzCodesView array{imported: int, assigned: int, free: int, registrantsWithoutCode: int, codes: list<MinimapperzCodeRow>}
 */
final readonly class MinimapperzCodes
{
    public const int MAX_LINES = 5000;

    public function __construct(
        private MinimapperzCodeRepositoryInterface $repository,
        private MinimapperzCodesQueryInterface $query,
        private EventRepositoryInterface $events,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return MinimapperzCodesView
     *
     * @throws NotFoundException when the event does not exist
     */
    public function overview(string $eventId): array
    {
        $this->requireEvent($eventId);

        return $this->view($eventId);
    }

    /**
     * @return array{added: int, duplicates: int, invalid: list<string>, overview: MinimapperzCodesView}
     *
     * @throws NotFoundException   when the event does not exist
     * @throws ValidationException when nothing usable was pasted, or far too much
     */
    public function import(string $eventId, string $text): array
    {
        $this->requireEvent($eventId);
        if (count(preg_split('/\R/u', $text) ?: []) > self::MAX_LINES) {
            throw new ValidationException('Liste trop longue.', ['codes' => [sprintf('%d lignes au plus par import.', self::MAX_LINES)]], 'invalid_minimapperz_codes');
        }
        $list = MinimapperzCodeList::parse($text);
        if ([] === $list->codes) {
            throw new ValidationException('Aucun code valide.', ['codes' => ['Colle un code par ligne, sans espace.']], 'invalid_minimapperz_codes');
        }

        $added = $this->repository->addCodes($eventId, $list->codes, $this->clock->now());

        return [
            'added' => $added,
            'duplicates' => count($list->codes) - $added,
            'invalid' => $list->invalid,
            'overview' => $this->view($eventId),
        ];
    }

    /**
     * Hands a free code to every active registrant who holds none.
     *
     * @return array{assigned: int, withoutCode: int, overview: MinimapperzCodesView}
     *
     * @throws NotFoundException when the event does not exist
     */
    public function assignToAll(string $eventId): array
    {
        $this->requireEvent($eventId);
        $now = $this->clock->now();
        $assigned = 0;
        $withoutCode = 0;
        foreach ($this->query->registrantsWithoutCode($eventId) as $userId) {
            if (null === $this->repository->assignFreeCode($eventId, $userId, $now)) {
                ++$withoutCode;
                continue;
            }
            ++$assigned;
        }

        return ['assigned' => $assigned, 'withoutCode' => $withoutCode, 'overview' => $this->view($eventId)];
    }

    /**
     * @return MinimapperzCodesView
     *
     * @throws NotFoundException when the event does not exist, or holds no such code given to anyone
     */
    public function detach(string $eventId, string $code): array
    {
        $this->requireEvent($eventId);
        if (!$this->repository->detach($eventId, $code)) {
            throw new NotFoundException('Ce code n\'est attribué à personne.', 'minimapperz_code_not_assigned');
        }

        return $this->view($eventId);
    }

    private function requireEvent(string $eventId): void
    {
        if (!$this->events->findById($eventId) instanceof Event) {
            throw new NotFoundException('Événement introuvable.', 'event_not_found');
        }
    }

    /** @return MinimapperzCodesView */
    private function view(string $eventId): array
    {
        $codes = $this->query->codes($eventId);
        $assigned = count(array_filter($codes, static fn (array $row): bool => null !== $row['userId']));

        return [
            'imported' => count($codes),
            'assigned' => $assigned,
            'free' => count($codes) - $assigned,
            'registrantsWithoutCode' => count($this->query->registrantsWithoutCode($eventId)),
            'codes' => $codes,
        ];
    }
}
