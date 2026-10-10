<?php

declare(strict_types=1);

namespace App\PersonalRuns\Domain\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
final class Run
{
    /** Archipelago's SlotType: 0 spectator, 1 player, 2 item-link group. Only 1 is a seat. */
    public const int IMPORTED_SLOT_TYPE_PLAYER = 1;

    public const string STATUS_DRAFT = 'draft';
    public const string STATUS_STARTING = 'starting';
    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_STOPPING = 'stopping';
    public const string STATUS_IDLE = 'idle';
    public const string STATUS_RESTARTING = 'restarting';
    public const string STATUS_COMPLETED = 'completed';
    public const string STATUS_CANCELLED = 'cancelled';

    /** Statuses that block deletion or modification */
    public const array ACTIVE_STATUSES = [self::STATUS_STARTING, self::STATUS_ACTIVE, self::STATUS_STOPPING];

    /**
     * Statuses indicating the run has been launched at least once - i.e. the games in it were
     * actually played. Used to surface "recently played" games (story 28.8). Excludes `draft`
     * (never launched) and `cancelled`/`starting` (no gameplay yet).
     */
    public const array LAUNCHED_STATUSES = [
        self::STATUS_ACTIVE,
        self::STATUS_STOPPING,
        self::STATUS_IDLE,
        self::STATUS_RESTARTING,
        self::STATUS_COMPLETED,
    ];

    /**
     * Statuts transitoires où une run peut rester bloquée si la session liée se coince ou si un webhook
     * de cycle de vie se perd. Réconciliés par le backstop planifié (story 17.14).
     */
    public const array STUCK_STATUSES = [self::STATUS_STARTING, self::STATUS_STOPPING, self::STATUS_RESTARTING];

    /** Seuils (secondes) sur updatedAt au-delà desquels la run transitoire est considérée bloquée. */
    public const array STUCK_THRESHOLDS = [
        self::STATUS_STARTING => 1800,    // 30 min - couvre une génération complète
        self::STATUS_STOPPING => 300,     // 5 min - l'arrêt est rapide
        self::STATUS_RESTARTING => 300,   // 5 min - la relance est rapide
    ];

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(name: 'owner_id', type: 'string', length: 32)]
        private string $ownerId,
        #[ORM\Column(type: 'string', length: 120)]
        private string $title,
        #[ORM\Column(type: 'string', length: 20)]
        private string $status,
        #[ORM\Column(name: 'invite_token', type: 'string', length: 64, unique: true)]
        private string $inviteToken,
        /** @var list<array{gameId: string}>|null */
        #[ORM\Column(name: 'game_selection_config', type: Types::JSON, nullable: true)]
        private ?array $gameSelectionConfig,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
        #[ORM\Column(name: 'connection_host', type: 'string', length: 255, nullable: true)]
        private ?string $connectionHost = null,
        #[ORM\Column(name: 'connection_port', type: 'integer', nullable: true)]
        private ?int $connectionPort = null,
        #[ORM\Column(name: 'connection_password', type: 'string', length: 120, nullable: true)]
        private ?string $connectionPassword = null,
        #[ORM\Column(name: 'session_id', type: 'string', length: 32, nullable: true)]
        private ?string $sessionId = null,
        /** Whether the finished run's recap is publicly shareable (story 32.5). Private by default. */
        #[ORM\Column(name: 'recap_public', type: 'boolean', options: ['default' => false])]
        private bool $recapPublic = false,
        /**
         * Object-storage key of a seed generated somewhere else (story 16.18). When set, the run
         * hosts that archive instead of generating one: no yamls are collected, no generation
         * container runs, and the detailed progression is unavailable because reachability would
         * need the yamls the archive does not carry.
         */
        #[ORM\Column(name: 'imported_output_key', type: 'string', length: 255, nullable: true)]
        private ?string $importedOutputKey = null,
        /**
         * The archive's slot table, read out of its multidata at import, plus who the run owner
         * assigned to each slot. Kept on the run rather than on the session because a relaunch
         * throws the session away and rebuilds it from here.
         *
         * @var list<array{slot: int, name: string, game: string, type: int, slotId: string, assignedUserIds: list<string>}>|null
         */
        #[ORM\Column(name: 'imported_slots', type: Types::JSON, nullable: true)]
        private ?array $importedSlots = null,
        /** Who may join without a link or a name (stories 43.14, 43.17): OPEN_INVITE (default), OPEN_FRIENDS or OPEN_MEMBERS. */
        #[ORM\Column(name: 'openness', type: 'string', length: 16, options: ['default' => self::OPEN_INVITE])]
        private string $openness = self::OPEN_INVITE,
        /** The seats offered, the owner aside; null leaves them unbounded. */
        #[ORM\Column(name: 'seats_wanted', type: 'smallint', nullable: true)]
        private ?int $seatsWanted = null,
        /** Story 43.17: the listing's short message, while the run is open to every member. */
        #[ORM\Column(name: 'pitch', type: 'string', length: 280, nullable: true)]
        private ?string $pitch = null,
        /** Story 43.17: when the owner plans to play, if they said. */
        #[ORM\Column(name: 'planned_for', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $plannedFor = null,
        /** Story 43.17: when the listing went up. */
        #[ORM\Column(name: 'listed_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $listedAt = null,
        /** Story 43.17: the latest member to join from the listing, which keeps it alive. */
        #[ORM\Column(name: 'last_arrival_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $lastArrivalAt = null,
    ) {
    }

    /** Story 43.14: only the link and invitations by name let a member in. */
    public const string OPEN_INVITE = 'invite';

    /** Story 43.14: the owner's friends find the run and join it themselves. */
    public const string OPEN_FRIENDS = 'friends';

    /** Story 43.17: a listing every member sees; the friends may join as well. */
    public const string OPEN_MEMBERS = 'members';

    public const int MAX_SEATS_WANTED = 30;

    public const int MAX_PITCH_LENGTH = 280;

    /** Story 43.17: a listing nobody joined for this long is taken down. */
    public const string LISTING_LIFETIME = '-14 days';

    /**
     * Opens the draft run to the owner's friends, or back to invitations only (story 43.14). Only a draft takes
     * players this way: past the launch the slots are frozen. A listing to every member goes through
     * {@see listForMembers}, which needs a message.
     */
    public function openTo(string $openness, ?int $seatsWanted, \DateTimeImmutable $now): void
    {
        $this->assertDraft();
        if (!in_array($openness, [self::OPEN_INVITE, self::OPEN_FRIENDS], true)) {
            throw new \InvalidArgumentException('Unknown openness.');
        }
        self::assertSeats($seatsWanted);

        $this->openness = $openness;
        $this->seatsWanted = self::OPEN_FRIENDS === $openness ? $seatsWanted : null;
        // Story 43.19: the listing's age stays, so taking it down and back up does not make it new again.
        $this->pitch = null;
        $this->plannedFor = null;
        $this->updatedAt = $now;
    }

    /**
     * Lists the draft run for every member (story 43.17), with a short message, the seats wanted and an optional
     * date. Editing a listing keeps its age: only an arrival renews it. Story 43.19: so does taking it down and back
     * up, as long as it had not expired; an expired listing put back up starts afresh.
     */
    public function listForMembers(string $pitch, ?int $seatsWanted, ?\DateTimeImmutable $plannedFor, \DateTimeImmutable $now): void
    {
        $this->assertDraft();
        $pitch = trim($pitch);
        if ('' === $pitch || mb_strlen($pitch) > self::MAX_PITCH_LENGTH) {
            throw new \InvalidArgumentException('A listing needs a message of at most 280 characters.');
        }
        self::assertSeats($seatsWanted);

        if (null === $this->listedAt || $this->lastSignOfLife() <= $now->modify(self::LISTING_LIFETIME)) {
            $this->listedAt = $now;
            $this->lastArrivalAt = null;
        }
        $this->openness = self::OPEN_MEMBERS;
        $this->seatsWanted = $seatsWanted;
        $this->pitch = $pitch;
        $this->plannedFor = $plannedFor;
        $this->updatedAt = $now;
    }

    /** A member joined from the listing (story 43.17): the listing lives on. */
    public function recordArrival(\DateTimeImmutable $now): void
    {
        if (self::OPEN_MEMBERS === $this->openness) {
            $this->lastArrivalAt = $now;
        }
    }

    /** Whether the listing went LISTING_LIFETIME without an arrival. */
    public function isListingExpired(\DateTimeImmutable $now): bool
    {
        if (!$this->isListed() || null === $this->listedAt) {
            return false;
        }

        return $this->lastSignOfLife() <= $now->modify(self::LISTING_LIFETIME);
    }

    /** When the listing went up or was last joined, whichever is later. */
    private function lastSignOfLife(): \DateTimeImmutable
    {
        $listedAt = $this->listedAt ?? $this->updatedAt;

        return null !== $this->lastArrivalAt && $this->lastArrivalAt > $listedAt ? $this->lastArrivalAt : $listedAt;
    }

    /** Takes the listing down: the run goes back to invitations only. */
    public function expireListing(\DateTimeImmutable $now): void
    {
        $this->openness = self::OPEN_INVITE;
        $this->seatsWanted = null;
        $this->clearListing();
        $this->updatedAt = $now;
    }

    /** Whether the owner's friends may join right now: open to them (or to every member) and still a draft. */
    public function isOpenToFriends(): bool
    {
        return in_array($this->openness, [self::OPEN_FRIENDS, self::OPEN_MEMBERS], true) && self::STATUS_DRAFT === $this->status;
    }

    /** Story 43.17: whether every member may join right now, from the listing. */
    public function isListed(): bool
    {
        return self::OPEN_MEMBERS === $this->openness && self::STATUS_DRAFT === $this->status;
    }

    /** Whether the run has no seat left, given how many members joined (the owner aside). */
    public function isFull(int $participants): bool
    {
        return null !== $this->seatsWanted && $participants >= $this->seatsWanted;
    }

    public function getOpenness(): string
    {
        return $this->openness;
    }

    public function getSeatsWanted(): ?int
    {
        return $this->seatsWanted;
    }

    public function getPitch(): ?string
    {
        return $this->pitch;
    }

    public function getPlannedFor(): ?\DateTimeImmutable
    {
        return $this->plannedFor;
    }

    public function getListedAt(): ?\DateTimeImmutable
    {
        return $this->listedAt;
    }

    private function assertDraft(): void
    {
        if (self::STATUS_DRAFT !== $this->status) {
            throw new \DomainException('Only a draft run can be opened.');
        }
    }

    private static function assertSeats(?int $seatsWanted): void
    {
        if (null !== $seatsWanted && ($seatsWanted < 1 || $seatsWanted > self::MAX_SEATS_WANTED)) {
            throw new \InvalidArgumentException('Seats out of range.');
        }
    }

    private function clearListing(): void
    {
        $this->pitch = null;
        $this->plannedFor = null;
        $this->listedAt = null;
        $this->lastArrivalAt = null;
    }

    public static function create(string $ownerId, string $title, \DateTimeImmutable $now): self
    {
        return new self(
            bin2hex(random_bytes(16)),
            $ownerId,
            trim($title),
            self::STATUS_DRAFT,
            bin2hex(random_bytes(32)),
            null,
            $now,
            $now,
        );
    }

    /**
     * Renames the run (story 17.24).
     *
     * Allowed at every status, terminal ones included: a title is a label, not configuration. The
     * read-only rule that {@see isTerminal} enforces covers invites and config overrides - things
     * that would change what a finished run *was*. Renaming "Partie Luigi's Mansion" into something
     * memorable months later changes nothing about the run itself.
     */
    public function rename(string $title, \DateTimeImmutable $now): void
    {
        $this->title = trim($title);
        $this->updatedAt = $now;
    }

    public function isOwnedBy(string $userId): bool
    {
        return $this->ownerId === $userId;
    }

    /**
     * Qui a le droit de démarrer cette run, dans l'état où elle est (story 16.14).
     *
     * Le propriétaire, toujours. Un participant, **uniquement pour reprendre une run en veille**.
     *
     * La distinction n'est pas cosmétique et c'est tout l'objet de la règle : `start()` couvre deux
     * usages que rien ne sépare côté appelant. Depuis `draft`, démarrer fige la configuration et les
     * slots de *tous* les participants - une décision qui engage autrui, donc réservée au
     * propriétaire. Depuis `idle`, la run a déjà été lancée telle quelle : la relancer ne décide de
     * rien, elle rallume ce qui existait. Sans ce droit, le propriétaire absent bloque la partie de
     * tout le monde.
     *
     * `$isParticipant` est passé en paramètre plutôt que résolu ici : le Domaine ne lit pas la base
     * (api/CLAUDE.md, AC-D3). L'appelant applicatif le calcule.
     */
    public function isStartAllowedFor(string $userId, bool $isParticipant): bool
    {
        if ($this->isOwnedBy($userId)) {
            return true;
        }

        return $isParticipant && self::STATUS_IDLE === $this->status;
    }

    /** Make the finished run's recap publicly shareable (story 32.5). */
    public function publishRecap(\DateTimeImmutable $now): void
    {
        $this->recapPublic = true;
        $this->updatedAt = $now;
    }

    /** Take the recap back to owner/participant-only. */
    public function unpublishRecap(\DateTimeImmutable $now): void
    {
        $this->recapPublic = false;
        $this->updatedAt = $now;
    }

    public function isRecapPublic(): bool
    {
        return $this->recapPublic;
    }

    /**
     * @param list<array{gameId: string}> $config
     */
    public function configureGames(array $config, \DateTimeImmutable $now): void
    {
        $this->gameSelectionConfig = $config;
        $this->updatedAt = $now;
    }

    public function regenerateInviteToken(\DateTimeImmutable $now): void
    {
        if ($this->isTerminal()) {
            throw new \DomainException('Cannot regenerate the invite link of a finished or cancelled run.');
        }

        $this->inviteToken = bin2hex(random_bytes(32));
        $this->updatedAt = $now;
    }

    public function cancel(\DateTimeImmutable $now): void
    {
        $nonCancellable = [self::STATUS_ACTIVE, self::STATUS_STOPPING];
        if (in_array($this->status, $nonCancellable, true)) {
            throw new \DomainException('Cannot cancel an active run.');
        }

        $this->status = self::STATUS_CANCELLED;
        // Story 43.19: a cancelled run takes no player any more; restored, it starts again on invitation.
        $this->openness = self::OPEN_INVITE;
        $this->seatsWanted = null;
        $this->clearListing();
        $this->updatedAt = $now;
    }

    public function unarchive(\DateTimeImmutable $now): void
    {
        if (self::STATUS_CANCELLED !== $this->status) {
            throw new \DomainException('Only cancelled runs can be unarchived.');
        }

        $this->status = null !== $this->sessionId ? self::STATUS_IDLE : self::STATUS_DRAFT;
        $this->updatedAt = $now;
    }

    /**
     * The connection password is not invented here (story 16.13). It used to be generated on the
     * spot, before anything knew whether this run wanted one at all - and it was overwritten by
     * `markRunning` with the session's own password the moment the server answered. Pre-generating
     * it only meant a run configured without a password still carried one.
     */
    public function start(\DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_STARTING;
        $this->updatedAt = $now;
    }

    /**
     * Owner-driven terminal finish (story 17.15): an active run is wrapped up so its session can be
     * archived and its goal-reached state counted in stats. Only an active run can be completed.
     */
    public function complete(\DateTimeImmutable $now): void
    {
        if (self::STATUS_ACTIVE !== $this->status) {
            throw new \DomainException('Only an active run can be completed.');
        }

        $this->connectionHost = null;
        $this->connectionPort = null;
        $this->connectionPassword = null;
        $this->status = self::STATUS_COMPLETED;
        $this->updatedAt = $now;
    }

    /** A run that hosts a seed generated elsewhere rather than one generated here (story 16.18). */
    public function isImportedSeed(): bool
    {
        return null !== $this->importedOutputKey;
    }

    public function getImportedOutputKey(): ?string
    {
        return $this->importedOutputKey;
    }

    /**
     * @return list<array{slot: int, name: string, game: string, type: int, slotId: string, assignedUserIds: list<string>}>
     */
    public function getImportedSlots(): array
    {
        return $this->importedSlots ?? [];
    }

    /**
     * The slots of the imported archive a person can actually be put on: Archipelago's spectator
     * slots (type 0) and item-link groups (type 2) are not seats.
     *
     * @return list<array{slotId: string, slot: int, name: string, game: string, assignedUserIds: list<string>}>
     */
    public function playableImportedSlots(): array
    {
        $out = [];
        foreach ($this->importedSlots ?? [] as $slot) {
            if (self::IMPORTED_SLOT_TYPE_PLAYER !== $slot['type']) {
                continue;
            }
            $out[] = [
                'slotId' => $slot['slotId'],
                'slot' => $slot['slot'],
                'name' => $slot['name'],
                'game' => $slot['game'],
                'assignedUserIds' => $slot['assignedUserIds'],
            ];
        }

        return $out;
    }

    /**
     * Attach an imported seed to this run, replacing any previous one.
     *
     * @param list<array{slot: int, name: string, game: string, type: int, slotId: string, assignedUserIds: list<string>}> $slots
     */
    public function importSeed(string $outputKey, array $slots, \DateTimeImmutable $now): void
    {
        $this->importedOutputKey = $outputKey;
        $this->importedSlots = $slots;
        $this->updatedAt = $now;
    }

    /**
     * Assign a slot of the imported archive to zero or more participants. The first of them owns
     * the slot; the rest are its co-players (story 16.17). Returns false when the slot is not part
     * of the archive.
     *
     * @param list<string> $userIds
     */
    public function assignImportedSlot(string $slotId, array $userIds, \DateTimeImmutable $now): bool
    {
        if (null === $this->importedSlots) {
            return false;
        }

        $updated = [];
        $found = false;
        foreach ($this->importedSlots as $slot) {
            if ($slot['slotId'] === $slotId) {
                $slot['assignedUserIds'] = $userIds;
                $found = true;
            }
            $updated[] = $slot;
        }

        if (!$found) {
            return false;
        }

        $this->importedSlots = $updated;
        $this->updatedAt = $now;

        return true;
    }

    public function attachSession(string $sessionId): void
    {
        $this->sessionId = $sessionId;
    }

    /**
     * The session is authoritative on the password, including when it has none (story 16.13). The
     * assignment used to be skipped on null, which was harmless while `start()` pre-generated one
     * and wrong as soon as a run could legitimately have none: a run switched to "no password"
     * would have kept displaying the secret from its previous launch.
     */
    public function markRunning(string $host, int $port, \DateTimeImmutable $now, ?string $password = null): void
    {
        $this->connectionHost = $host;
        $this->connectionPort = $port;
        $this->connectionPassword = $password;
        $this->status = self::STATUS_ACTIVE;
        $this->updatedAt = $now;
    }

    public function stop(\DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_STOPPING;
        $this->updatedAt = $now;
    }

    /**
     * The runner stopped. Never reopens a run that is already over (story 17.25).
     *
     * Stopping the container is the normal *consequence* of finishing a run, not a reason to undo
     * it: without this guard the `session.stopped` webhook demoted a freshly completed run back to
     * idle about twenty seconds after its owner finished it - hiding its recap, which only shows on
     * a completed run, and making a finished run startable again.
     */
    public function markStopped(\DateTimeImmutable $now): void
    {
        if ($this->isTerminal()) {
            return;
        }

        $this->connectionHost = null;
        $this->connectionPort = null;
        $this->connectionPassword = null;
        $this->status = self::STATUS_IDLE;
        $this->updatedAt = $now;
    }

    /**
     * The session this run points at reached its end (story 17.25).
     *
     * Used by reconciliation for a run that never got its owner-driven completion - a run stuck in
     * a transitional state whose session is already finished is over, not idle. Idle would leave it
     * restartable and recap-less.
     */
    public function markSessionFinished(\DateTimeImmutable $now): void
    {
        if ($this->isTerminal()) {
            return;
        }

        $this->connectionHost = null;
        $this->connectionPort = null;
        $this->connectionPassword = null;
        $this->status = self::STATUS_COMPLETED;
        $this->updatedAt = $now;
    }

    public function markRestarting(\DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_RESTARTING;
        $this->updatedAt = $now;
    }

    public function resetAfterValidationFailure(\DateTimeImmutable $now): void
    {
        $this->status = self::STATUS_DRAFT;
        $this->connectionPassword = null;
        $this->updatedAt = $now;
    }

    /** True si la run est dans un statut transitoire depuis plus longtemps que son seuil. */
    public function isStuck(\DateTimeImmutable $now): bool
    {
        $threshold = self::STUCK_THRESHOLDS[$this->status] ?? null;
        if (null === $threshold) {
            return false;
        }

        return ($now->getTimestamp() - $this->updatedAt->getTimestamp()) > $threshold;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getOwnerId(): string
    {
        return $this->ownerId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * True once the run has left `draft`: the multiworld has been (or is being) generated and is now
     * fixed - resume always replays the saved game - so participant game selection, slot YAML and the
     * owner's game config can no longer be changed. A paused (`idle`) run is included: editing it would
     * be a no-op since the existing session is what resumes.
     */
    public function isLockedForEditing(): bool
    {
        return self::STATUS_DRAFT !== $this->status;
    }

    /**
     * A finished (owner-completed) or cancelled run is terminal: it is read-only for the owner. Invite
     * regeneration and config-override edits are refused past this point, while reads (spoiler / patch
     * downloads) stay available (issue #338).
     */
    public function isTerminal(): bool
    {
        return in_array($this->status, [self::STATUS_COMPLETED, self::STATUS_CANCELLED], true);
    }

    public function getInviteToken(): string
    {
        return $this->inviteToken;
    }

    /** @return list<array{gameId: string}>|null */
    public function getGameSelectionConfig(): ?array
    {
        return $this->gameSelectionConfig;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function getConnectionHost(): ?string
    {
        return $this->connectionHost;
    }

    public function getConnectionPort(): ?int
    {
        return $this->connectionPort;
    }

    public function getConnectionPassword(): ?string
    {
        return $this->connectionPassword;
    }

    public function getSessionId(): ?string
    {
        return $this->sessionId;
    }
}
