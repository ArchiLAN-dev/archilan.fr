<?php

declare(strict_types=1);

namespace App\Sessions\Application\Command;

use App\Community\Application\Support\Notifier;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Sessions\Application\Port\PasseurBridgeInterface;
use App\Sessions\Application\Query\PasseurCandidate;
use App\Sessions\Application\Query\PasseurCandidatesQuery;
use App\Sessions\Application\Support\PasseurTerms;
use App\Sessions\Application\Support\PasseurTermsResolver;
use App\Sessions\Domain\Entity\PasseurOpening;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionPlayersSnapshot;
use App\Sessions\Domain\Repository\PasseurOpeningRepositoryInterface;
use App\Sessions\Domain\Repository\SessionPlayersSnapshotRepositoryInterface;
use App\Sessions\Domain\Repository\SessionSlotRepositoryInterface;
use App\Sessions\Domain\Service\PasseurPrice;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use App\Wallet\Application\Command\RecordPelleMovement;
use App\Wallet\Application\Command\RecordPelleMovementInput;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Repository\PelleMovementRepositoryInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Update;

/**
 * Story 52.4: a blocked player pays for a chest of an absent player's world, the Passeur opens it.
 *
 * Every refusal comes before the debit: the Passeur off, no block, a chest that is no longer a candidate, the day's
 * limit of the absent world reached, a price that moved since the player read it, not enough pelles. Then the opening
 * is written pending - the lock on the chest - the buyer pays, the bridge opens. Opened: the absent player gets their
 * share, the feed and the absent player are told. Not opened: the buyer gets everything back.
 *
 * An event pays in its silver pelles only, never in gold; a private run in its bronze.
 */
final readonly class CallThePasseur
{
    private const int MAX_REQUEST_ID_LENGTH = 64;
    public const string NOTIFICATION_TYPE = 'passeur_opened';

    public function __construct(
        private PasseurTermsResolver $terms,
        private PasseurCandidatesQuery $candidates,
        private PasseurOpeningRepositoryInterface $openings,
        private PasseurBridgeInterface $bridge,
        private SessionPlayersSnapshotRepositoryInterface $snapshots,
        private SessionSlotRepositoryInterface $slots,
        private RegistrationRepositoryInterface $registrations,
        private PelleMovementRepositoryInterface $movements,
        private RecordPelleMovement $record,
        private Notifier $notifier,
        private HubInterface $hub,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws NotFoundException   when the session does not exist
     * @throws ConflictException   when the Passeur cannot open this chest now (nothing is debited), or the bridge did
     *                             not open it (refunded)
     * @throws ValidationException when the request is malformed or the player cannot pay
     */
    public function execute(string $userId, string $sessionId, int $slotIndex, int $findingSlot, int $locationId, int $expectedPrice, string $requestId): CallThePasseurResult
    {
        if ('' === $requestId || \strlen($requestId) > self::MAX_REQUEST_ID_LENGTH) {
            throw new ValidationException('Identifiant de requête manquant.', [], 'invalid_request_id');
        }
        $requestKey = sprintf('passeur:%s:%s', $userId, $requestId);
        $previous = $this->openings->findByRequestKey($requestKey);
        if (null !== $previous) {
            return $this->replay($previous);
        }

        $terms = $this->terms->of($sessionId);
        if (null === $terms || !$terms->enabled()) {
            throw new ConflictException('Le Passeur n\'est pas ouvert dans cette partie.', 'passeur_disabled');
        }
        if (Session::STATUS_RUNNING !== $terms->session->getStatus()) {
            throw new ConflictException('La session n\'est pas en cours.', 'session_not_running');
        }

        $now = $this->clock->now();
        $offer = $this->candidates->forSlot($sessionId, $slotIndex, false);
        if (null === $offer->blockedSince) {
            throw new ConflictException('Tu n\'es pas bloqué : le Passeur n\'intervient qu\'en BK.', 'passeur_not_blocked');
        }
        $candidate = $this->candidate($offer->candidates, $findingSlot, $locationId);

        [$dayStart, $dayEnd] = PasseurPrice::dayOf($now);
        if ($this->openings->countForWorld($sessionId, $findingSlot, $dayStart, $dayEnd) >= $terms->config->passeurDailyLimit) {
            throw new ConflictException('Le Passeur a déjà ouvert assez de coffres de ce monde aujourd\'hui.', 'passeur_daily_limit');
        }

        $price = PasseurPrice::at($terms->config->passeurBasePrice, $terms->config->passeurDecayPercent, $terms->config->passeurFloorPercent, $offer->blockedSince, $now);
        if ($price !== $expectedPrice) {
            throw new ConflictException(sprintf('Le prix a changé : %d pelles.', $price), 'passeur_price_changed', ['price' => $price]);
        }

        $kind = $terms->pelleKind();
        $balance = $this->movements->balance($userId, $kind, $terms->event?->getId(), $terms->run?->getId());
        if ($balance < $price) {
            throw new ValidationException(sprintf('Il te faut %d pelles %s pour faire appel au Passeur.', $price, 'bronze' === $terms->kind ? 'de bronze' : 'd\'argent'), ['price' => $price, 'balance' => $balance], 'insufficient_pelles');
        }

        $absentName = $candidate->item->findingPlayerName;
        $buyerName = $this->slotName($sessionId, $slotIndex) ?? 'un joueur';
        $absentRegistrationId = $this->registrationOf($sessionId, $findingSlot);
        $opening = $this->openings->claim(new PasseurOpening(
            bin2hex(random_bytes(16)), $sessionId, $requestKey, $userId, $slotIndex, $findingSlot,
            $this->userOf($terms, $absentRegistrationId), $locationId, mb_substr($candidate->item->locationName, 0, 200), mb_substr($candidate->item->itemName, 0, 200),
            $terms->kind, $terms->event?->getId(), $terms->run?->getId(), $price, PasseurOpening::STATUS_PENDING, $now,
            buyerName: mb_substr($buyerName, 0, 100), absentName: mb_substr($absentName, 0, 100),
        ));
        if (null === $opening) {
            $previous = $this->openings->findByRequestKey($requestKey);
            if (null !== $previous) {
                return $this->replay($previous);
            }

            throw new ConflictException('Le Passeur ouvre déjà ce coffre.', 'passeur_busy');
        }

        $label = sprintf('Le Passeur : %s', $opening->getItemName());
        try {
            $this->record->record($this->movement($opening, $userId, -$price, PelleReason::PasseurPurchase, $label, 'debit'));
        } catch (\Throwable $e) {
            $opening->markFailed('debit_refused', $now);
            $this->openings->flush();

            throw $e;
        }

        $outcome = $this->bridge->open($sessionId, $findingSlot, $locationId);
        if (PasseurBridgeInterface::OPENED !== $outcome) {
            $this->record->record($this->movement($opening, $userId, $price, PelleReason::PasseurRefund, 'Remboursement, '.lcfirst($label), 'refund', true));
            $opening->markFailed($outcome, $this->clock->now());
            $this->openings->flush();

            throw new ConflictException(PasseurBridgeInterface::ALREADY_OPENED === $outcome ? 'Ce coffre vient d\'être ouvert. Tes pelles t\'ont été rendues.' : 'Le Passeur n\'a pas pu ouvrir le coffre. Tes pelles t\'ont été rendues.', 'passeur_'.$outcome);
        }

        $share = $this->payShare($opening, PasseurPrice::share($price, $terms->config->passeurSharePercent));
        $opening->markOpened($share, $this->clock->now());
        $this->openings->flush();

        $this->announce($opening, $buyerName, $absentName);
        $this->tellTheAbsent($terms, $opening, $absentRegistrationId, $buyerName);

        return new CallThePasseurResult($opening->getId(), $terms->kind, $price, $share, $opening->getItemName(), $absentName, false);
    }

    /**
     * @param list<PasseurCandidate> $candidates
     */
    private function candidate(array $candidates, int $findingSlot, int $locationId): PasseurCandidate
    {
        foreach ($candidates as $candidate) {
            if ($candidate->item->findingSlot === $findingSlot && $candidate->item->locationId === $locationId) {
                return $candidate;
            }
        }

        throw new ConflictException('Ce coffre n\'est plus proposé par le Passeur (son joueur est revenu, ou il a été ouvert).', 'passeur_no_candidate');
    }

    private function replay(PasseurOpening $opening): CallThePasseurResult
    {
        if (PasseurOpening::STATUS_FAILED === $opening->getStatus()) {
            throw new ConflictException('Le Passeur n\'a pas pu ouvrir le coffre. Tes pelles t\'ont été rendues.', 'passeur_'.($opening->getFailureCode() ?? PasseurBridgeInterface::NOT_OPENED));
        }
        $kind = 'bronze' === $opening->getKind() ? 'bronze' : 'event';

        return new CallThePasseurResult($opening->getId(), $kind, $opening->getPrice(), $opening->getShare(), $opening->getItemName(), $this->slotName($opening->getSessionId(), $opening->getFindingSlot()) ?? '', true);
    }

    /** The absent player's share, in the pelles the buyer paid; 0 when nobody can receive it (a banned member). */
    private function payShare(PasseurOpening $opening, int $share): int
    {
        $absent = $opening->getAbsentUserId();
        if ($share <= 0 || null === $absent) {
            return 0;
        }

        try {
            $this->record->record($this->movement($opening, $absent, $share, PelleReason::PasseurShare, sprintf('Le Passeur a ouvert un coffre de ton monde (%s)', $opening->getLocationName()), 'share'));

            return $share;
        } catch (\Throwable $e) {
            $this->logger->warning('passeur.share_not_paid', ['opening' => $opening->getId(), 'error' => $e->getMessage()]);

            return 0;
        }
    }

    private function movement(PasseurOpening $opening, string $userId, int $amount, PelleReason $reason, string $label, string $step, bool $byAdmin = false): RecordPelleMovementInput
    {
        $bronze = 'bronze' === $opening->getKind();

        return new RecordPelleMovementInput(
            $userId, $amount, $bronze ? PelleKind::Bronze : PelleKind::Event,
            $bronze ? null : $opening->getEventId(), $reason, mb_substr($label, 0, 200), null,
            sprintf('passeur:%s:%s', $opening->getId(), $step),
            byAdmin: $byAdmin,
            runId: $bronze ? $opening->getRunId() : null,
        );
    }

    /** The live feed of the session gets the Passeur's line; the bridge's `!admin` echo is masked there. */
    private function announce(PasseurOpening $opening, string $buyerName, string $absentName): void
    {
        $event = [
            'type' => 'passeur',
            'text' => sprintf('Le Passeur a ouvert un coffre du monde de %s pour %s', $absentName, $buyerName),
            'timestamp' => $this->clock->now()->format(\DateTimeInterface::ATOM),
            'item' => ['name' => $opening->getItemName()],
            'location' => ['id' => $opening->getLocationId(), 'name' => $opening->getLocationName()],
            'sender' => ['slot' => $opening->getFindingSlot(), 'name' => $absentName],
            'receiver' => ['slot' => $opening->getBuyerSlot(), 'name' => $buyerName],
        ];
        try {
            $this->hub->publish(new Update(sprintf('runs/%s/feed', $opening->getSessionId()), json_encode($event, \JSON_THROW_ON_ERROR), true));
        } catch (\Throwable $e) {
            $this->logger->warning('passeur.feed_not_published', ['opening' => $opening->getId(), 'error' => $e->getMessage()]);
        }
    }

    private function tellTheAbsent(PasseurTerms $terms, PasseurOpening $opening, ?string $absentRegistrationId, string $buyerName): void
    {
        $absent = $opening->getAbsentUserId();
        if (null === $absent) {
            return;
        }

        $url = null !== $terms->run
            ? '/runs/'.$terms->run->getId()
            : sprintf('/evenements/%s/inscription/%s/session', $terms->event?->getId() ?? '', $absentRegistrationId ?? '');
        try {
            $this->notifier->notify($absent, self::NOTIFICATION_TYPE, [
                'sessionTitle' => $terms->run?->getTitle() ?? $terms->event?->getTitle(),
                'buyerName' => $buyerName,
                'itemName' => $opening->getItemName(),
                'locationName' => $opening->getLocationName(),
                'share' => $opening->getShare(),
                'kind' => $opening->getKind(),
                'url' => $url,
            ]);
        } catch (\Throwable $e) {
            $this->logger->warning('passeur.absent_not_notified', ['opening' => $opening->getId(), 'error' => $e->getMessage()]);
        }
    }

    /** The registration of a slot (a private run stores its player's user id there). */
    private function registrationOf(string $sessionId, int $slotIndex): ?string
    {
        $name = $this->slotName($sessionId, $slotIndex);

        return null === $name ? null : $this->slots->findBySessionAndSlotName($sessionId, $name)?->getRegistrationId();
    }

    private function userOf(PasseurTerms $terms, ?string $registrationId): ?string
    {
        if (null === $registrationId || null !== $terms->run) {
            return $registrationId;
        }

        return $this->registrations->findById($registrationId)?->getUserId();
    }

    private function slotName(string $sessionId, int $slotIndex): ?string
    {
        $snapshot = $this->snapshots->findBySessionId($sessionId);
        $slots = $snapshot instanceof SessionPlayersSnapshot ? ($snapshot->getPayload()['slots'] ?? null) : null;
        $slot = is_array($slots) ? ($slots[$slotIndex] ?? null) : null;
        $name = is_array($slot) ? ($slot['slot_name'] ?? null) : null;

        return is_string($name) && '' !== $name ? $name : null;
    }
}
