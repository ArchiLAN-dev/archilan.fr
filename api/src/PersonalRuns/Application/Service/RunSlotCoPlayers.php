<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Service;

use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Application\Port\AchievementRecomputeTriggerInterface;
use App\Sessions\Domain\Entity\SlotCoPlayer;
use App\Sessions\Domain\Repository\SlotCoPlayerRepositoryInterface;
use App\Sessions\Domain\Service\SlotCoPlayerRoster;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Who else plays a slot of a private run (story 16.17).
 *
 * A Minecraft world is played by three people on one Archipelago slot; only the member who declared
 * it existed for the platform. The run owner is the one who says who else is on it - they are the
 * person who knows how the party is actually organised, and the alternative (each player claiming
 * slots themselves) would let anyone attach to someone else's game.
 *
 * Co-players never touch the slot's configuration: the yaml stays the owner's, so a shared slot has
 * exactly one configuration and nothing to arbitrate.
 */
final readonly class RunSlotCoPlayers
{
    public function __construct(
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private SlotCoPlayerRepositoryInterface $coPlayers,
        private CommunityUserDirectoryQueryInterface $directory,
        private SlotCoPlayerRoster $roster,
        private AchievementRecomputeTriggerInterface $achievements,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Replace the whole roster of a slot in one call: the caller sends the list it wants, not a
     * diff, so adding and removing are the same operation and repeating a call changes nothing.
     *
     * @param list<string> $userIds
     *
     * @return array{found: bool, authorized: bool, errors: array<string, list<string>>, coPlayers: list<array{userId: string, displayName: string, slug: string|null, avatarUrl: string|null}>|null}
     */
    public function replace(string $runId, string $callerId, string $slotId, array $userIds): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return $this->result(found: false);
        }

        if (!$run->isOwnedBy($callerId)) {
            return $this->result(found: true, authorized: false);
        }

        $participants = $this->participants->findByRunId($runId);
        $owner = $this->slotOwner($participants, $slotId);
        if (null === $owner) {
            return $this->result(found: true, errors: ['slotId' => ['Slot introuvable.']]);
        }

        $resolved = $this->roster->resolve(
            $owner,
            $userIds,
            array_map(static fn (RunParticipant $participant): string => $participant->getUserId(), $participants),
        );

        if ([] !== $resolved['errors']) {
            return $this->result(found: true, errors: ['userIds' => $this->messages($resolved['errors'])]);
        }

        $existing = $this->coPlayers->findBySlotIds([$slotId]);
        $previous = array_map(static fn (SlotCoPlayer $coPlayer): string => $coPlayer->getUserId(), $existing);

        // A diff rather than a delete-then-recreate: Doctrine orders inserts before deletes inside a
        // unit of work, so re-sending an unchanged roster would insert a row the delete had not
        // removed yet and hit the unique index. Untouched rows also keep the date they were added.
        //
        // Dropping someone drops the points they had on this slot. Nothing is lost doing so: the
        // statistics are computed from the slots, never stored.
        foreach ($existing as $coPlayer) {
            if (!in_array($coPlayer->getUserId(), $resolved['userIds'], true)) {
                $this->coPlayers->remove($coPlayer);
            }
        }

        foreach ($resolved['userIds'] as $userId) {
            if (!in_array($userId, $previous, true)) {
                $this->coPlayers->persist(SlotCoPlayer::create(
                    bin2hex(random_bytes(16)),
                    $slotId,
                    $userId,
                    $this->clock->now(),
                ));
            }
        }

        $this->coPlayers->flush();

        // The facts change the moment the rows do, but achievements are granted by their own engine:
        // without this, someone added to a slot of an already finished run would wait for their next
        // run to be given what they already earned. Both sides of the change are recomputed, since
        // removing someone can take an achievement's ground away too.
        $touched = array_values(array_unique([...$previous, ...$resolved['userIds']]));
        if ([] !== $touched) {
            $this->achievements->recomputeForUsers($touched);
        }

        $this->logger->info('personal_run.slot_co_players_replaced', [
            'runId' => $runId,
            'slotId' => $slotId,
            'count' => count($resolved['userIds']),
        ]);

        return $this->result(found: true, coPlayers: $this->identities($resolved['userIds']));
    }

    /**
     * Co-players of several slots at once, keyed by game slot id, for the payloads that list slots.
     *
     * @param list<string> $slotIds
     *
     * @return array<string, list<array{userId: string, displayName: string, slug: string|null, avatarUrl: string|null}>>
     */
    public function forSlots(array $slotIds): array
    {
        $rows = $this->coPlayers->findBySlotIds($slotIds);
        if ([] === $rows) {
            return [];
        }

        /** @var array<string, list<string>> $userIdsBySlot */
        $userIdsBySlot = [];
        $allUserIds = [];
        foreach ($rows as $row) {
            $userIdsBySlot[$row->getSlotId()][] = $row->getUserId();
            $allUserIds[] = $row->getUserId();
        }

        $cards = $this->directory->cards(array_values(array_unique($allUserIds)));
        $names = $this->directory->namesFor(array_values(array_unique($allUserIds)));

        $out = [];
        foreach ($userIdsBySlot as $slotId => $ids) {
            $out[$slotId] = $this->toIdentities($ids, $cards, $names);
        }

        return $out;
    }

    /**
     * Forget the co-players of slots that no longer exist - a participant who drops a game drops the
     * people who were playing it with them.
     *
     * @param list<string> $slotIds
     */
    public function forget(array $slotIds): void
    {
        foreach ($slotIds as $slotId) {
            $this->coPlayers->deleteBySlotId($slotId);
        }

        if ([] !== $slotIds) {
            $this->coPlayers->flush();
        }
    }

    /**
     * @param list<RunParticipant> $participants
     *
     * @return string|null the member who declared the slot, or null when no participant has it
     */
    private function slotOwner(array $participants, string $slotId): ?string
    {
        foreach ($participants as $participant) {
            if (null !== $participant->getSlot($slotId)) {
                return $participant->getUserId();
            }
        }

        return null;
    }

    /**
     * @param list<string> $userIds
     *
     * @return list<array{userId: string, displayName: string, slug: string|null, avatarUrl: string|null}>
     */
    private function identities(array $userIds): array
    {
        if ([] === $userIds) {
            return [];
        }

        return $this->toIdentities($userIds, $this->directory->cards($userIds), $this->directory->namesFor($userIds));
    }

    /**
     * @param list<string>                                                                                         $userIds
     * @param array<string, array{userId: string, slug: string, displayName: string|null, avatarUrl: string|null}> $cards
     * @param array<string, string>                                                                                $names
     *
     * @return list<array{userId: string, displayName: string, slug: string|null, avatarUrl: string|null}>
     */
    private function toIdentities(array $userIds, array $cards, array $names): array
    {
        $out = [];
        foreach ($userIds as $userId) {
            $card = $cards[$userId] ?? null;
            $cardName = $card['displayName'] ?? null;
            $slug = $card['slug'] ?? '';

            $out[] = [
                'userId' => $userId,
                // cards() only covers listable members; namesFor() names everyone else, so a
                // co-player without a public profile is still shown by name rather than blank.
                'displayName' => null !== $cardName && '' !== $cardName ? $cardName : ($names[$userId] ?? ''),
                'slug' => '' !== $slug ? $slug : null,
                'avatarUrl' => $card['avatarUrl'] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param list<string> $codes
     *
     * @return list<string>
     */
    private function messages(array $codes): array
    {
        $messages = [];
        foreach ($codes as $code) {
            $messages[] = match ($code) {
                SlotCoPlayerRoster::ERROR_OWNER => 'Ce joueur possède déjà ce slot.',
                SlotCoPlayerRoster::ERROR_NOT_A_PARTICIPANT => 'Ce joueur ne participe pas à cette partie.',
                default => 'Co-joueur invalide.',
            };
        }

        return $messages;
    }

    /**
     * @param array<string, list<string>>                                                                      $errors
     * @param list<array{userId: string, displayName: string, slug: string|null, avatarUrl: string|null}>|null $coPlayers
     *
     * @return array{found: bool, authorized: bool, errors: array<string, list<string>>, coPlayers: list<array{userId: string, displayName: string, slug: string|null, avatarUrl: string|null}>|null}
     */
    private function result(bool $found = false, bool $authorized = true, array $errors = [], ?array $coPlayers = null): array
    {
        return ['found' => $found, 'authorized' => $authorized, 'errors' => $errors, 'coPlayers' => $coPlayers];
    }
}
