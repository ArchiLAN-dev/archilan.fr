<?php

declare(strict_types=1);

namespace App\Sessions\Application\Query;

use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Sessions\Application\Support\SlotsPlayedBy;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;

/**
 * Resolves, for an event registrant, the durable output archive of their latest session and the slot
 * names they play in it (story 17.32), the way PersonalRunPatchQuery does for a private run. The
 * caller filters the archive to those slots, so a registrant only ever downloads their own patch(es).
 */
final readonly class RegistrationPatchQuery
{
    /** Past generation: the archive exists even when the session never recorded its key. */
    private const array GENERATED_STATUSES = [
        Session::STATUS_GENERATED,
        Session::STATUS_LAUNCHING,
        Session::STATUS_RUNNING,
        Session::STATUS_IDLE,
        Session::STATUS_RESTARTING,
        Session::STATUS_STOPPED,
        Session::STATUS_CRASHED,
        Session::STATUS_FINISHED,
    ];

    public function __construct(
        private RegistrationRepositoryInterface $registrations,
        private PlayerConnectionQueryInterface $playerConnectionQuery,
        private SessionRepositoryInterface $sessions,
        private SessionSlotRepositoryInterface $slots,
        private SlotsPlayedBy $playedSlots,
    ) {
    }

    /**
     * Null when the registration is not the user's confirmed one, when its session is not generated
     * yet, or when the user plays no slot in it.
     *
     * @return array{outputKey: string, slotNames: list<string>, allSlotNames: list<string>}|null
     */
    public function forRegistration(string $registrationId, string $userId): ?array
    {
        $registration = $this->registrations->findById($registrationId);
        if (!$registration instanceof Registration
            || $registration->getUserId() !== $userId
            || !$registration->isReserved()
            || null === $registration->getSubmittedAt()
        ) {
            return null;
        }

        $sessionId = $this->playerConnectionQuery->findLatestSessionIdByRegistrationId($registrationId);
        $session = null === $sessionId ? null : $this->sessions->findById($sessionId);
        if (!$session instanceof Session) {
            return null;
        }

        $outputKey = $session->getGeneratedOutputKey();
        if (null === $outputKey && in_array($session->getStatus(), self::GENERATED_STATUSES, true)) {
            $outputKey = $session->getId().'/output/archive.zip';
        }
        if (null === $outputKey) {
            return null;
        }

        // Owned slots plus the ones this member co-plays (story 16.17): a shared slot's patch goes
        // to everyone who plays it.
        $slotNames = [];
        foreach ($this->playedSlots->inSession($session->getId(), $registrationId, $userId) as $slot) {
            $slotNames[] = $slot->getSlotName();
        }
        if ([] === $slotNames) {
            return null;
        }

        $allSlotNames = [];
        foreach ($this->slots->findBySessionId($session->getId()) as $sessionSlot) {
            $allSlotNames[] = $sessionSlot->getSlotName();
        }

        return ['outputKey' => $outputKey, 'slotNames' => $slotNames, 'allSlotNames' => $allSlotNames];
    }
}
