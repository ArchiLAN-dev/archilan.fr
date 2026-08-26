<?php

declare(strict_types=1);

namespace App\PersonalRuns\Application\Service;

use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Application\Port\SeedArchiveGatewayInterface;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * Hosting a seed generated somewhere else (story 16.18).
 *
 * A seed can already exist - made on the Archipelago website, by a member locally, or by another
 * group - and regenerating it would produce a different multiworld. Importing it means the archive
 * *is* the party: no yamls are collected, no generation container runs.
 *
 * What that costs is the detailed progression. Reachability re-generates the world to know which
 * checks are doable, and it needs each player's yaml, which an output archive does not carry. The
 * feature says so with a banner rather than quietly hiding the tabs.
 */
final readonly class PersonalRunSeedImport
{
    /** An Archipelago output archive with its patches; generous, but not a way to fill the bucket. */
    public const int MAX_ARCHIVE_BYTES = 200 * 1024 * 1024;

    public function __construct(
        private RunRepositoryInterface $runs,
        private RunParticipantRepositoryInterface $participants,
        private SeedArchiveGatewayInterface $seeds,
        private MinioStorageInterface $minioStorage,
        private ClockInterface $clock,
        private LoggerInterface $logger,
        private string $minioSessionsBucket,
    ) {
    }

    /**
     * Attach an already-generated archive to a run.
     *
     * The file is validated before anything is written: an archive whose multidata cannot be read
     * is refused with the reason, because "this is not a seed" is something the member can act on.
     *
     * @return array{found: bool, authorized: bool, errors: array<string, list<string>>, slots: list<array<string, mixed>>|null}
     */
    public function import(string $runId, string $callerId, string $contents, string $filename): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return $this->result(found: false);
        }

        if (!$run->isOwnedBy($callerId)) {
            return $this->result(found: true, authorized: false);
        }

        if ($run->isLockedForEditing()) {
            return $this->result(found: true, errors: ['file' => ['Cette partie est déjà lancée : la seed ne peut plus être remplacée.']]);
        }

        if ('' === $contents) {
            return $this->result(found: true, errors: ['file' => ['Le fichier est vide.']]);
        }

        if (strlen($contents) > self::MAX_ARCHIVE_BYTES) {
            return $this->result(found: true, errors: ['file' => ['Le fichier dépasse la taille maximale autorisée.']]);
        }

        $inspection = $this->seeds->inspect($contents, $this->safeFilename($filename));
        if (isset($inspection['error'])) {
            return $this->result(found: true, errors: ['file' => [$this->message($inspection['error'])]]);
        }

        $slots = [];
        foreach ($inspection['slots'] as $slot) {
            $slots[] = [
                'slot' => $slot['slot'],
                'name' => $slot['name'],
                'game' => $slot['game'],
                'type' => $slot['type'],
                // The game slot id story 16.17 anchors co-players on. Minted once at import so it
                // survives every relaunch, exactly like a participant's declared game slot.
                'slotId' => bin2hex(random_bytes(8)),
                'assignedUserIds' => [],
            ];
        }

        if (!$this->hasPlayableSlot($slots)) {
            return $this->result(found: true, errors: ['file' => ['Cette archive ne contient aucun slot jouable.']]);
        }

        $outputKey = $runId.'/imported/'.$this->safeFilename($filename);
        $this->minioStorage->upload($this->minioSessionsBucket, $outputKey, $contents);

        $run->importSeed($outputKey, $slots, $this->clock->now());
        $this->runs->flush();

        $this->logger->info('personal_run.seed_imported', [
            'runId' => $runId,
            'slotCount' => count($slots),
            'seedName' => $inspection['seedName'],
        ]);

        return $this->result(found: true, slots: $run->playableImportedSlots());
    }

    /**
     * Assign a slot of the archive to zero or more participants: the first owns it, the rest are its
     * co-players (story 16.17). A slot may stay unassigned - an imported seed can hold worlds nobody
     * in this party plays.
     *
     * @param list<string> $userIds
     *
     * @return array{found: bool, authorized: bool, errors: array<string, list<string>>, slots: list<array<string, mixed>>|null}
     */
    public function assign(string $runId, string $callerId, string $slotId, array $userIds): array
    {
        $run = $this->runs->findById($runId);
        if (!$run instanceof Run) {
            return $this->result(found: false);
        }

        if (!$run->isOwnedBy($callerId)) {
            return $this->result(found: true, authorized: false);
        }

        if (!$run->isImportedSeed()) {
            return $this->result(found: true, errors: ['slotId' => ['Cette partie n\'a pas de seed importée.']]);
        }

        if ($run->isTerminal()) {
            return $this->result(found: true, errors: ['slotId' => ['Cette partie est terminée.']]);
        }

        $participantIds = array_map(
            static fn (RunParticipant $participant): string => $participant->getUserId(),
            $this->participants->findByRunId($runId),
        );

        $assigned = [];
        foreach ($userIds as $userId) {
            if ('' === $userId || in_array($userId, $assigned, true)) {
                continue;
            }
            if (!in_array($userId, $participantIds, true)) {
                return $this->result(found: true, errors: ['userIds' => ['Ce joueur ne participe pas à cette partie.']]);
            }
            $assigned[] = $userId;
        }

        if (!$run->assignImportedSlot($slotId, $assigned, $this->clock->now())) {
            return $this->result(found: true, errors: ['slotId' => ['Slot introuvable dans cette archive.']]);
        }

        $this->runs->flush();

        $this->logger->info('personal_run.imported_slot_assigned', [
            'runId' => $runId,
            'slotId' => $slotId,
            'count' => count($assigned),
        ]);

        return $this->result(found: true, slots: $run->playableImportedSlots());
    }

    /**
     * @param list<array{slot: int, name: string, game: string, type: int, slotId: string, assignedUserIds: list<string>}> $slots
     */
    private function hasPlayableSlot(array $slots): bool
    {
        return array_any($slots, fn ($slot) => Run::IMPORTED_SLOT_TYPE_PLAYER === $slot['type']);
    }

    /** Never let an uploaded name decide where a file lands. */
    private function safeFilename(string $filename): string
    {
        $base = basename(str_replace('\\', '/', $filename));
        $base = preg_replace('/[^A-Za-z0-9._-]/', '_', $base) ?? '';

        return '' !== $base ? $base : 'archive.zip';
    }

    private function message(string $error): string
    {
        return match ($error) {
            'runner_unavailable' => 'Le service de génération est indisponible, réessaie dans un moment.',
            'no_slots' => 'Cette archive ne contient aucun slot.',
            default => 'Ce fichier n\'est pas une seed Archipelago lisible ('.$error.').',
        };
    }

    /**
     * @param array<string, list<string>>     $errors
     * @param list<array<string, mixed>>|null $slots
     *
     * @return array{found: bool, authorized: bool, errors: array<string, list<string>>, slots: list<array<string, mixed>>|null}
     */
    private function result(bool $found = false, bool $authorized = true, array $errors = [], ?array $slots = null): array
    {
        return ['found' => $found, 'authorized' => $authorized, 'errors' => $errors, 'slots' => $slots];
    }
}
