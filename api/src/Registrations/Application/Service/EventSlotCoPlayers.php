<?php

declare(strict_types=1);

namespace App\Registrations\Application\Service;

use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Registrations\Domain\Entity\Registration;
use App\Registrations\Domain\Repository\RegistrationRepositoryInterface;
use App\Sessions\Application\Port\AchievementRecomputeTriggerInterface;
use App\Sessions\Domain\Entity\SlotCoPlayer;
use App\Sessions\Domain\Repository\SlotCoPlayerRepositoryInterface;
use App\Sessions\Domain\Service\SlotCoPlayerRoster;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Who else plays a slot of an event (story 11.10).
 *
 * A session of an event may take only part of the slots (story 11.9); a registrant left out of it can still play it
 * on someone else's slot. Same storage as the co-players of a personal run (story 16.17), keyed by the game slot id,
 * so the access already granted to a co-player (connection, patch, hints, tracking, statistics, achievements) applies
 * as it is. Managed by an admin or by the registrant who declared the slot; the candidates are the event's confirmed
 * registrants. The slot's YAML stays its holder's.
 *
 * @phpstan-type CoPlayerIdentity array{userId: string, displayName: string}
 */
final readonly class EventSlotCoPlayers
{
    public function __construct(
        private RegistrationRepositoryInterface $registrations,
        private SlotCoPlayerRepositoryInterface $coPlayers,
        private SlotCoPlayerRoster $roster,
        private CommunityUserDirectoryQueryInterface $directory,
        private AchievementRecomputeTriggerInterface $achievements,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * The roster of a slot, who may be added, and whether the caller may change it. Visible to an admin, the holder
     * and the slot's co-players.
     *
     * @return array{found: bool, authorized: bool, coPlayers: list<CoPlayerIdentity>, candidates: list<CoPlayerIdentity>, canManage: bool}
     */
    public function view(string $eventId, string $slotId, string $callerId, bool $isAdmin): array
    {
        $confirmed = $this->confirmedRegistrations($eventId);
        $holder = $this->holder($eventId, $slotId);
        if (!$holder instanceof Registration) {
            return ['found' => false, 'authorized' => false, 'coPlayers' => [], 'candidates' => [], 'canManage' => false];
        }

        $current = $this->currentUserIds($slotId);
        $canManage = $isAdmin || $holder->getUserId() === $callerId;
        if (!$canManage && !in_array($callerId, $current, true)) {
            return ['found' => true, 'authorized' => false, 'coPlayers' => [], 'candidates' => [], 'canManage' => false];
        }

        $candidateIds = [];
        foreach ($confirmed as $registration) {
            if ($registration->getUserId() !== $holder->getUserId()) {
                $candidateIds[] = $registration->getUserId();
            }
        }

        return [
            'found' => true,
            'authorized' => true,
            'coPlayers' => $this->identities($current),
            'candidates' => $canManage ? $this->identities($candidateIds) : [],
            'canManage' => $canManage,
        ];
    }

    /**
     * Replace the whole roster of a slot: the caller sends the list it wants, so adding and removing are one
     * operation and repeating a call changes nothing.
     *
     * @param list<string> $userIds
     *
     * @return array{found: bool, authorized: bool, errors: array<string, list<string>>, coPlayers: list<CoPlayerIdentity>}
     */
    public function replace(string $eventId, string $slotId, string $callerId, bool $isAdmin, array $userIds): array
    {
        $holder = $this->holder($eventId, $slotId);
        if (!$holder instanceof Registration) {
            return ['found' => false, 'authorized' => false, 'errors' => [], 'coPlayers' => []];
        }
        if (!$isAdmin && $holder->getUserId() !== $callerId) {
            return ['found' => true, 'authorized' => false, 'errors' => [], 'coPlayers' => []];
        }

        $participants = array_map(
            static fn (Registration $registration): string => $registration->getUserId(),
            $this->confirmedRegistrations($eventId),
        );
        $resolved = $this->roster->resolve($holder->getUserId(), $userIds, $participants);
        if ([] !== $resolved['errors']) {
            return ['found' => true, 'authorized' => true, 'errors' => ['userIds' => $this->messages($resolved['errors'])], 'coPlayers' => []];
        }

        $existing = $this->coPlayers->findBySlotIds([$slotId]);
        $previous = array_map(static fn (SlotCoPlayer $coPlayer): string => $coPlayer->getUserId(), $existing);

        // A diff rather than a delete-then-recreate: Doctrine orders inserts before deletes inside a unit of work, so
        // re-sending an unchanged roster would hit the unique index (same reasoning as RunSlotCoPlayers).
        foreach ($existing as $coPlayer) {
            if (!in_array($coPlayer->getUserId(), $resolved['userIds'], true)) {
                $this->coPlayers->remove($coPlayer);
            }
        }
        foreach ($resolved['userIds'] as $userId) {
            if (!in_array($userId, $previous, true)) {
                $this->coPlayers->persist(SlotCoPlayer::create(bin2hex(random_bytes(16)), $slotId, $userId, $this->clock->now()));
            }
        }
        $this->coPlayers->flush();

        // Achievements are granted by their own engine: both sides of the change are recomputed, since removing
        // someone can take an achievement's ground away too.
        $touched = array_values(array_unique([...$previous, ...$resolved['userIds']]));
        if ([] !== $touched) {
            $this->achievements->recomputeForUsers($touched);
        }

        $this->logger->info('event.slot_co_players_replaced', [
            'eventId' => $eventId,
            'slotId' => $slotId,
            'count' => count($resolved['userIds']),
        ]);

        return ['found' => true, 'authorized' => true, 'errors' => [], 'coPlayers' => $this->identities($resolved['userIds'])];
    }

    /**
     * @return list<Registration> the event's registrations that are reserved and submitted
     */
    private function confirmedRegistrations(string $eventId): array
    {
        return array_values(array_filter(
            $this->registrations->findBy(['eventId' => $eventId]),
            static fn (Registration $registration): bool => $registration->isReserved() && null !== $registration->getSubmittedAt(),
        ));
    }

    /** The live registration of the event that declared the slot. */
    private function holder(string $eventId, string $slotId): ?Registration
    {
        foreach ($this->registrations->findBy(['eventId' => $eventId]) as $registration) {
            if ($registration->isReserved() && null !== $registration->getSlot($slotId)) {
                return $registration;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private function currentUserIds(string $slotId): array
    {
        return array_map(static fn (SlotCoPlayer $coPlayer): string => $coPlayer->getUserId(), $this->coPlayers->findBySlotIds([$slotId]));
    }

    /**
     * @param list<string> $userIds
     *
     * @return list<CoPlayerIdentity>
     */
    private function identities(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        $names = $this->directory->namesFor($userIds);
        $out = [];
        foreach ($userIds as $userId) {
            $out[] = ['userId' => $userId, 'displayName' => $names[$userId] ?? ''];
        }
        usort($out, static fn (array $a, array $b): int => strcasecmp($a['displayName'], $b['displayName']));

        return $out;
    }

    /**
     * @param list<string> $codes
     *
     * @return list<string>
     */
    private function messages(array $codes): array
    {
        return array_map(static fn (string $code): string => match ($code) {
            SlotCoPlayerRoster::ERROR_OWNER => 'Ce joueur est déjà le titulaire de ce slot.',
            SlotCoPlayerRoster::ERROR_NOT_A_PARTICIPANT => 'Seuls les inscrits confirmés de l\'événement peuvent être co-joueurs.',
            default => 'Co-joueur invalide.',
        }, $codes);
    }
}
