<?php

declare(strict_types=1);

namespace App\GameSelection\Domain\Entity;

use App\GameSelection\Domain\ValueObject\PlatformCategory;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\UniqueConstraint(name: 'uniq_games_slug', columns: ['slug'])]
final class Game
{
    public const string AVAILABILITY_AVAILABLE = 'available';
    public const string AVAILABILITY_UNAVAILABLE = 'unavailable';
    public const string AVAILABILITY_EXPERIMENTAL = 'experimental';

    public const string UPDATE_STATUS_NOT_TRACKED = 'not_tracked';
    public const string UPDATE_STATUS_UNKNOWN = 'unknown';
    public const string UPDATE_STATUS_UP_TO_DATE = 'up_to_date';
    public const string UPDATE_STATUS_UPDATE_AVAILABLE = 'update_available';

    #[ORM\OneToOne(mappedBy: 'game', cascade: ['persist', 'remove'])]
    private ?GameCatalogSync $catalogSync = null;

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(type: 'string', length: 120)]
        private string $name,
        #[ORM\Column(type: 'string', length: 120)]
        private string $slug,
        #[ORM\Column(type: 'text')]
        private string $description,
        #[ORM\Column(name: 'cover_image_url', type: 'text', nullable: true)]
        private ?string $coverImageUrl,
        #[ORM\Column(name: 'cover_image_alt', type: 'string', length: 160)]
        private string $coverImageAlt,
        #[ORM\Column(name: 'cover_image_credit', type: 'string', length: 160)]
        private string $coverImageCredit,
        #[ORM\Column(type: 'string', length: 32)]
        private string $availability,
        #[ORM\Column(name: 'archipelago_game_name', type: 'string', length: 120, nullable: true)]
        private ?string $archipelagoGameName,
        #[ORM\Column(name: 'created_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $createdAt,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
        #[ORM\Column(name: 'apworld_storage_key', type: 'string', length: 500, nullable: true)]
        private ?string $apworldStorageKey = null,
        #[ORM\Column(name: 'apworld_hash', type: 'string', length: 64, nullable: true)]
        private ?string $apworldHash = null,
        #[ORM\Column(name: 'apworld_uploaded_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $apworldUploadedAt = null,
        #[ORM\Column(name: 'default_yaml', type: 'text', nullable: true)]
        private ?string $defaultYaml = null,
        #[ORM\Column(name: 'apworld_minio_key', type: 'string', length: 500, nullable: true)]
        private ?string $apworldMinioKey = null,
        #[ORM\Column(name: 'availability_locked', type: 'boolean', options: ['default' => false])]
        private bool $availabilityLocked = false,
        /**
         * What the apworld says each of its options is, from introspection (stories 9.25 / 9.33).
         *
         * Range bounds only at first, which is why the editor guessed every other type from the
         * shape of the value. Rows written back then carry `{min, max, default}` with no `type`.
         *
         * A `dict` row may also carry `keys` (story 9.51): per sub-setting, the values it accepts,
         * for the worlds whose `OptionDict` declares a `schema`. Absent for all the others, which is
         * the majority - and absent means "nothing was declared", not "an empty list was".
         *
         * @var array<string, array{type?: string, min?: int, max?: int, default?: int|string|bool|null, values?: list<string>, keys?: array<string, array{values: list<string>}>}>|null
         */
        #[ORM\Column(name: 'option_types', type: 'json', nullable: true)]
        private ?array $optionTypes = null,
        /**
         * What an admin says the sub-settings of a dict option accept (story 9.52).
         *
         * Kept apart from `optionTypes` rather than merged into it, for two reasons. The practical
         * one: `recordOptionTypes()` replaces that column wholesale at every upload and every
         * backfill, so a curation stored there would be erased by the next re-introspection. The
         * one that matters more: what the apworld declares and what we decided are two different
         * claims, and only keeping them apart makes the curation reversible.
         *
         * Shape: option key -> sub-setting -> {values, closed}.
         *
         * @var array<string, array<string, array{values: list<string>, closed: bool}>>|null
         */
        #[ORM\Column(name: 'dict_option_values', type: 'json', nullable: true)]
        private ?array $dictOptionValues = null,
        /**
         * Ordered per-game install tutorial steps (story 31.1).
         *
         * @var list<array{type: string, title: string, description: string}>|null
         */
        #[ORM\Column(name: 'install_steps', type: 'json', nullable: true)]
        private ?array $installSteps = null,
        /**
         * Static apworld location names (the World class's location_name_to_id keys), from introspection
         * (story 4.14). Free-text suggestion hint for the location-typed YAML options; null when the
         * apworld has no apworld/introspection yet.
         *
         * @var list<string>|null
         */
        #[ORM\Column(name: 'location_names', type: 'json', nullable: true)]
        private ?array $locationNames = null,
        // Free-text admin-only notes about the game (apworld quirks, config pitfalls, decision
        // history). Strictly internal: never exposed on public/game-selection payloads (story 3.12).
        #[ORM\Column(name: 'admin_notes', type: 'text', nullable: true)]
        private ?string $adminNotes = null,
        // Optional second description covering the Archipelago side of the game - what gets
        // randomized, the goal, apworld quirks. Public, unlike adminNotes above (story 3.13).
        #[ORM\Column(name: 'archipelago_description', type: 'text', nullable: true)]
        private ?string $archipelagoDescription = null,
        // Temporary admin kill switch (story 11.4): a disabled game stays visible in the game
        // pickers but cannot be newly selected for a session. Orthogonal to availability, which
        // is long-term catalogue status owned by the sheet sync.
        #[ORM\Column(name: 'disabled_at', type: 'datetimetz_immutable', nullable: true)]
        private ?\DateTimeImmutable $disabledAt = null,
        #[ORM\Column(name: 'disabled_message', type: 'string', length: 500, nullable: true)]
        private ?string $disabledMessage = null,
        /**
         * Admin-curated platform families overriding the IGDB-derived list (story 9.47). IGDB
         * describes the game - often 8 platforms - while the Archipelago world may support
         * only one. Kept on the game, not on the catalog sync, so an IGDB resync never
         * discards it. Null means "derive from IGDB".
         *
         * @var list<string>|null
         */
        #[ORM\Column(name: 'platform_families', type: 'json', nullable: true)]
        private ?array $platformFamilies = null,
    ) {
    }

    public static function create(
        string $name,
        string $slug,
        string $description,
        ?string $coverImageUrl,
        string $coverImageAlt,
        string $coverImageCredit,
        string $availability,
        \DateTimeImmutable $now,
    ): self {
        return new self(
            bin2hex(random_bytes(16)),
            trim($name),
            self::normalizeSlug($slug),
            trim($description),
            null !== $coverImageUrl ? trim($coverImageUrl) : null,
            trim($coverImageAlt),
            trim($coverImageCredit),
            $availability,
            null,
            $now,
            $now,
        );
    }

    public function update(
        string $name,
        string $slug,
        string $description,
        ?string $coverImageUrl,
        string $coverImageAlt,
        string $coverImageCredit,
        string $availability,
        \DateTimeImmutable $now,
    ): void {
        $this->name = trim($name);
        $this->slug = self::normalizeSlug($slug);
        $this->description = trim($description);
        $this->coverImageUrl = null !== $coverImageUrl ? trim($coverImageUrl) : null;
        $this->coverImageAlt = trim($coverImageAlt);
        $this->coverImageCredit = trim($coverImageCredit);
        $this->availability = $availability;
        $this->updatedAt = $now;
    }

    public function configureApworld(string $storageKey, string $hash, string $archipelagoGameName, string $defaultYaml, \DateTimeImmutable $now): void
    {
        $this->apworldStorageKey = $storageKey;
        $this->apworldHash = $hash;
        $this->apworldUploadedAt = $now;
        // Strip a leading UTF-8 BOM: apworld templates authored on Windows often carry one,
        // which breaks the YAML parser downstream (it reads an empty game). Sanitize at the source.
        $this->defaultYaml = str_starts_with($defaultYaml, "\u{FEFF}") ? substr($defaultYaml, 3) : $defaultYaml;
        $this->archipelagoGameName = $archipelagoGameName;
        $this->updatedAt = $now;
    }

    public function recordApworldMinioUpload(string $key): void
    {
        $this->apworldMinioKey = $key;
    }

    /**
     * Replace the default YAML template served to players (story 9.45). Some generated
     * templates are not valid configurations - Atlyss ships one where main_class equals
     * secondary_class, which the world rejects - and this value seeds every new slot plus
     * the launch fallback, so an admin must be able to fix it without re-uploading a
     * patched apworld. Same BOM strip as the generated path.
     */
    public function overrideDefaultYaml(string $defaultYaml, \DateTimeImmutable $now): void
    {
        $this->defaultYaml = str_starts_with($defaultYaml, "\u{FEFF}") ? substr($defaultYaml, 3) : $defaultYaml;
        $this->updatedAt = $now;
    }

    public function isApworldReady(): bool
    {
        return null !== $this->apworldStorageKey;
    }

    public function isYamlReady(): bool
    {
        return null !== $this->archipelagoGameName && '' !== $this->archipelagoGameName;
    }

    /**
     * @return list<string>
     */
    public static function supportedAvailabilities(): array
    {
        return [
            self::AVAILABILITY_AVAILABLE,
            self::AVAILABILITY_UNAVAILABLE,
            self::AVAILABILITY_EXPERIMENTAL,
        ];
    }

    public static function normalizeSlug(string $slug): string
    {
        return strtolower(trim($slug));
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getDescription(): string
    {
        return $this->description;
    }

    public function getCoverImageUrl(): ?string
    {
        return $this->coverImageUrl;
    }

    public function getCoverImageAlt(): string
    {
        return $this->coverImageAlt;
    }

    public function getCoverImageCredit(): string
    {
        return $this->coverImageCredit;
    }

    public function getAvailability(): string
    {
        return $this->availability;
    }

    public function getArchipelagoGameName(): ?string
    {
        return $this->archipelagoGameName;
    }

    public function getApworldStorageKey(): ?string
    {
        return $this->apworldStorageKey;
    }

    public function getApworldMinioKey(): ?string
    {
        return $this->apworldMinioKey;
    }

    public function getApworldHash(): ?string
    {
        return $this->apworldHash;
    }

    public function getApworldUploadedAt(): ?\DateTimeImmutable
    {
        return $this->apworldUploadedAt;
    }

    public function getDefaultYaml(): ?string
    {
        return $this->defaultYaml;
    }

    /**
     * @return array<string, array{type?: string, min?: int, max?: int, default?: int|string|bool|null, values?: list<string>, keys?: array<string, array{values: list<string>}>}>|null
     */
    public function getOptionTypes(): ?array
    {
        return $this->optionTypes;
    }

    /**
     * The apworld's own answer about each of its options (story 9.33).
     *
     * Rows written before that story carry only `{min, max, default}` and no `type`: the readers
     * treat a missing type as "range when there are bounds, unknown otherwise", so a game keeps
     * working until its apworld is re-introspected. That is why the table was widened in place
     * rather than doubled by a second column.
     *
     * @param array<string, array{type?: string, min?: int, max?: int, default?: int|string|bool|null, values?: list<string>, keys?: array<string, array{values: list<string>}>}>|null $optionTypes
     */
    public function recordOptionTypes(?array $optionTypes): void
    {
        $this->optionTypes = null === $optionTypes || [] === $optionTypes ? null : $optionTypes;
    }

    /**
     * The admin curation alone, without the introspected table (story 9.52).
     *
     * @return array<string, array<string, array{values: list<string>, closed: bool}>>|null
     */
    public function getDictOptionValues(): ?array
    {
        return $this->dictOptionValues;
    }

    public function hasDictOptionValues(string $optionKey): bool
    {
        return isset($this->dictOptionValues[$optionKey]) && [] !== $this->dictOptionValues[$optionKey];
    }

    /**
     * Set or clear the curation for one dict option. Null - or an empty map - clears it, which
     * hands that option back to whatever introspection had to say about it.
     *
     * @param array<string, array{values: list<string>, closed: bool}>|null $subOptions
     */
    public function overrideDictOptionValues(string $optionKey, ?array $subOptions, \DateTimeImmutable $now): void
    {
        $current = $this->dictOptionValues ?? [];

        if (null === $subOptions || [] === $subOptions) {
            unset($current[$optionKey]);
        } else {
            $current[$optionKey] = $subOptions;
        }

        $this->dictOptionValues = [] === $current ? null : $current;
        $this->updatedAt = $now;
    }

    /**
     * The option table as every reader should see it: introspection, with the admin curation laid
     * over it per sub-setting (story 9.52).
     *
     * The merge is per sub-setting, not per option: curating `battle_style` must not hide what
     * introspection knew about `text_speed` in the same block. And it happens on **read** - writing
     * it back into `optionTypes` would lose the distinction at the first re-introspection.
     *
     * A curation for an option introspection never described is ignored: there is no entry to lay
     * it over, and inventing one would assert on the admin's word that the option is a dict.
     *
     * @return array<string, array{type?: string, min?: int, max?: int, default?: int|string|bool|null, values?: list<string>, keys?: array<string, array{values: list<string>, closed?: bool}>}>|null
     */
    public function getEffectiveOptionTypes(): ?array
    {
        $types = $this->optionTypes;
        if (null === $types || null === $this->dictOptionValues) {
            return $types;
        }

        foreach ($this->dictOptionValues as $optionKey => $subOptions) {
            if (!isset($types[$optionKey])) {
                continue;
            }

            $keys = $types[$optionKey]['keys'] ?? [];
            foreach ($subOptions as $subKey => $spec) {
                $keys[$subKey] = $spec;
            }
            $types[$optionKey]['keys'] = $keys;
        }

        return $types;
    }

    /**
     * @return list<string>|null
     */
    public function getLocationNames(): ?array
    {
        return $this->locationNames;
    }

    /**
     * @param list<string>|null $locationNames
     */
    public function recordLocationNames(?array $locationNames): void
    {
        $this->locationNames = null === $locationNames || [] === $locationNames ? null : $locationNames;
    }

    public function getAdminNotes(): ?string
    {
        return $this->adminNotes;
    }

    public function recordAdminNotes(?string $adminNotes): void
    {
        $trimmed = null === $adminNotes ? null : trim($adminNotes);
        $this->adminNotes = null === $trimmed || '' === $trimmed ? null : $trimmed;
    }

    public function getArchipelagoDescription(): ?string
    {
        return $this->archipelagoDescription;
    }

    public function recordArchipelagoDescription(?string $description): void
    {
        $trimmed = null === $description ? null : trim($description);
        $this->archipelagoDescription = null === $trimmed || '' === $trimmed ? null : $trimmed;
    }

    /**
     * @return list<array{type: string, title: string, description: string}>
     */
    public function getInstallSteps(): array
    {
        return $this->installSteps ?? [];
    }

    /**
     * @param list<array{type: string, title: string, description: string}>|null $steps
     */
    public function updateInstallSteps(?array $steps): void
    {
        $this->installSteps = null === $steps || [] === $steps ? null : $steps;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function isDisabled(): bool
    {
        return null !== $this->disabledAt;
    }

    public function getDisabledMessage(): ?string
    {
        return $this->disabledMessage;
    }

    public function disable(?string $message, \DateTimeImmutable $now): void
    {
        $this->disabledAt ??= $now;
        $trimmed = null === $message ? null : trim($message);
        $this->disabledMessage = null === $trimmed || '' === $trimmed ? null : mb_substr($trimmed, 0, 500);
    }

    public function enable(): void
    {
        $this->disabledAt = null;
        $this->disabledMessage = null;
    }

    public function isAvailabilityLocked(): bool
    {
        return $this->availabilityLocked;
    }

    public function lockAvailability(): void
    {
        $this->availabilityLocked = true;
    }

    public function unlockAvailability(): void
    {
        $this->availabilityLocked = false;
    }

    public function updateCatalogueMetadata(
        ?string $catalogSheetName = null,
        ?string $sourceUrl = null,
        ?string $deployedVersion = null,
        bool $availabilityLocked = false,
    ): void {
        $this->availabilityLocked = $availabilityLocked;

        if (null === $this->catalogSync) {
            new GameCatalogSync($this, $catalogSheetName, $sourceUrl, $deployedVersion);
        } else {
            $this->catalogSync->update(
                $catalogSheetName ?? $this->catalogSync->getCatalogSheetName(),
                $sourceUrl ?? $this->catalogSync->getApworldSourceUrl(),
                $deployedVersion ?? $this->catalogSync->getApworldDeployedVersion(),
                $this->catalogSync->getIgdbId(),
            );
        }
    }

    public static function normalizeApworldSourceUrl(string $url): ?string
    {
        if ('' === $url) {
            return null;
        }

        $parsed = parse_url($url);
        if (false === $parsed || 'https' !== ($parsed['scheme'] ?? '')) {
            return null;
        }

        $host = $parsed['host'] ?? '';
        $path = rtrim($parsed['path'] ?? '/', '/');

        // GitLab direct file URL - blob → raw normalisation
        if ('gitlab.com' === $host) {
            // Accept /-/blob/{branch}/{file}.apworld and /-/raw/{branch}/{file}.apworld
            if (1 === preg_match('#/-/(blob|raw)/.+\.apworld$#i', $path)) {
                return 'https://gitlab.com'.preg_replace('#/-/blob/#', '/-/raw/', $path);
            }

            return null;
        }

        if ('github.com' !== $host) {
            return null;
        }

        $query = isset($parsed['query']) ? '?'.$parsed['query'] : '';

        $parts = array_values(array_filter(
            explode('/', $path),
            static fn (string $s): bool => '' !== $s,
        ));

        if (count($parts) < 2) {
            return null;
        }

        $subParts = array_slice($parts, 2);

        if (0 === count($subParts)) {
            // /owner/repo - valid
        } elseif ('releases' === $subParts[0]) {
            if (1 === count($subParts)) {
                // /releases - valid
            } elseif ('latest' === $subParts[1] && 2 === count($subParts)) {
                // /releases/latest - valid
            } elseif ('tag' === $subParts[1] && 3 === count($subParts)) {
                // /releases/tag/{version} - valid
            } else {
                return null;
            }
        } elseif ('tree' === $subParts[0] && count($subParts) >= 2) {
            // /tree/{branch} - valid
        } elseif ('raw' === $subParts[0] && count($subParts) >= 2 && str_ends_with(strtolower($path), '.apworld')) {
            // /raw/{refs/heads/branch/...file}.apworld or /raw/{commit}/{file}.apworld
            // Normalize to raw.githubusercontent.com which serves the binary directly.
            $owner = $parts[0];
            $repo = $parts[1];
            $rawRest = implode('/', array_slice($subParts, 1));

            return "https://raw.githubusercontent.com/{$owner}/{$repo}/{$rawRest}";
        } elseif (str_ends_with(strtolower($path), '.apworld')) {
            // Any other github.com URL ending in .apworld - pass through as-is.
        } else {
            return null;
        }

        return 'https://github.com'.$path.$query;
    }

    public function attachCatalogSync(GameCatalogSync $sync): void
    {
        $this->catalogSync = $sync;
    }

    public function getCatalogSync(): ?GameCatalogSync
    {
        return $this->catalogSync;
    }

    public function getCatalogSheetName(): ?string
    {
        return $this->catalogSync?->getCatalogSheetName();
    }

    public function getApworldSourceUrl(): ?string
    {
        return $this->catalogSync?->getApworldSourceUrl();
    }

    public function recordApworldCheck(string $latestVersion, \DateTimeImmutable $checkedAt, ?string $releaseUrl = null): void
    {
        $this->catalogSync?->recordApworldCheck($latestVersion, $checkedAt, $releaseUrl);
    }

    public function computeApworldUpdateStatus(): string
    {
        return $this->catalogSync?->computeApworldUpdateStatus() ?? self::UPDATE_STATUS_NOT_TRACKED;
    }

    public function getApworldDeployedVersion(): ?string
    {
        return $this->catalogSync?->getApworldDeployedVersion();
    }

    public function getApworldLatestVersion(): ?string
    {
        return $this->catalogSync?->getApworldLatestVersion();
    }

    public function getApworldReleaseUrl(): ?string
    {
        return $this->catalogSync?->getApworldReleaseUrl();
    }

    public function getApworldCheckedAt(): ?\DateTimeImmutable
    {
        return $this->catalogSync?->getApworldCheckedAt();
    }

    public function getIgdbId(): ?int
    {
        return $this->catalogSync?->getIgdbId();
    }

    public function getSteamAppId(): ?int
    {
        return $this->catalogSync?->getSteamAppId();
    }

    public function recordSteamAppId(?int $steamAppId): void
    {
        $this->catalogSync?->recordSteamAppId($steamAppId);
    }

    /**
     * @return list<array{id: int, name: string}>|null
     */
    public function getPlatforms(): ?array
    {
        return $this->catalogSync?->getPlatforms();
    }

    /**
     * @param list<array{id: int, name: string}>|null $platforms
     */
    public function recordPlatforms(?array $platforms): void
    {
        $this->catalogSync?->recordPlatforms($platforms);
    }

    /**
     * The platform families to show for this game: the admin's choice when set, the
     * IGDB-derived list otherwise (story 9.47).
     *
     * @return list<string>
     */
    public function platformFamilies(): array
    {
        return PlatformCategory::resolve($this->platformFamilies, $this->getPlatforms() ?? []);
    }

    public function hasPlatformOverride(): bool
    {
        return null !== $this->platformFamilies && [] !== $this->platformFamilies;
    }

    /**
     * Set or clear the admin platform override. Null restores the IGDB-derived list.
     *
     * @param list<string>|null $families
     */
    public function overridePlatformFamilies(?array $families, \DateTimeImmutable $now): void
    {
        $this->platformFamilies = null === $families || [] === $families ? null : $families;
        $this->updatedAt = $now;
    }

    public function isAdultContent(): bool
    {
        return $this->catalogSync?->isAdultContent() ?? false;
    }

    public function isBundledWithAp(): bool
    {
        return $this->catalogSync?->isBundledWithAp() ?? false;
    }
}
