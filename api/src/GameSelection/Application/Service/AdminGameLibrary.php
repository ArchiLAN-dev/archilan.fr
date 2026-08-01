<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Service;

use App\CatalogSync\Application\Service\ApworldVersionChecker;
use App\GameSelection\Application\Port\GameUsageCounterInterface;
use App\GameSelection\Application\Query\AdminGameListQueryInterface;
use App\GameSelection\Application\Support\GamePlatformResolver;
use App\GameSelection\Application\Support\GameTutorialSeeder;
use App\GameSelection\Application\Support\InstallStepsNormalizer;
use App\GameSelection\Application\Support\InstallStepsReader;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Entity\GameCatalogSync;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\GameSelection\Domain\ValueObject\PlatformCategory;
use App\Identity\Application\Support\ValidationErrors;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use App\Sessions\Application\Support\GenerationFailureParser;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

final readonly class AdminGameLibrary
{
    /** Mirrors GAME_DESCRIPTION_MAX in the frontend's content-limits.ts. */
    private const int MAX_DESCRIPTION = 5000;

    public function __construct(
        private GameRepositoryInterface $gameRepository,
        private AdminGameListQueryInterface $adminGameListQuery,
        private LoggerInterface $logger,
        private RunnerGatewayInterface $runnerGateway,
        private MinioStorageInterface $minioStorage,
        private ClockInterface $clock,
        private string $minioApworldsBucket,
        private ApworldVersionChecker $apworldVersionChecker,
        private GameUsageCounterInterface $gameUsageCounter,
        private GamePlatformResolver $platformResolver,
        private InstallStepsNormalizer $stepsNormalizer,
        private GameTutorialSeeder $tutorialSeeder,
        private InstallStepsReader $stepsReader,
    ) {
    }

    /**
     * Replace a game's install tutorial with the given ordered steps (story 31.1).
     *
     * @param array<mixed> $rawSteps
     *
     * @return array{found: bool, game?: array<string, mixed>, errors: array<string, list<string>>}
     */
    public function saveTutorial(string $gameId, array $rawSteps): array
    {
        $game = $this->gameRepository->findById($gameId);
        if (!$game instanceof Game) {
            return ['found' => false, 'errors' => []];
        }

        $result = $this->stepsNormalizer->normalize($rawSteps);
        if ([] !== $result['errors']) {
            return ['found' => true, 'errors' => ['steps' => $result['errors']]];
        }

        $game->updateInstallSteps($result['steps']);
        $this->gameRepository->save($game);

        $this->logger->info('game.tutorial_saved', ['gameId' => $gameId, 'stepCount' => count($result['steps'])]);

        return ['found' => true, 'game' => $this->detailPayload($game), 'errors' => []];
    }

    /**
     * Save the admin-only free-text notes for a game (story 3.12). Empty/whitespace clears them.
     *
     * @return array{found: bool, game?: array<string, mixed>, errors: array<string, list<string>>}
     */
    public function saveNotes(string $gameId, ?string $notes): array
    {
        $game = $this->gameRepository->findById($gameId);
        if (!$game instanceof Game) {
            return ['found' => false, 'errors' => []];
        }

        $game->recordAdminNotes($notes);
        $this->gameRepository->save($game);

        $this->logger->info('game.admin_notes_saved', ['gameId' => $gameId, 'hasNotes' => null !== $game->getAdminNotes()]);

        return ['found' => true, 'game' => $this->detailPayload($game), 'errors' => []];
    }

    /**
     * Seed a draft install tutorial from existing data (bundled / apworld / sheet links). Only
     * overwrites an existing tutorial when $force is true.
     *
     * @return array{found: bool, game?: array<string, mixed>, errors: array<string, list<string>>}
     */
    public function seedTutorial(string $gameId, bool $force): array
    {
        $game = $this->gameRepository->findById($gameId);
        if (!$game instanceof Game) {
            return ['found' => false, 'errors' => []];
        }

        if ($force || [] === $game->getInstallSteps()) {
            $game->updateInstallSteps($this->tutorialSeeder->buildFor($game));
            $this->gameRepository->save($game);
            $this->logger->info('game.tutorial_seeded', ['gameId' => $gameId]);
        }

        return ['found' => true, 'game' => $this->detailPayload($game), 'errors' => []];
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function list(int $page = 1, int $perPage = 50, string $search = '', ?string $availability = null, ?bool $yamlReady = null, ?bool $apworldReady = null, string $sort = 'name', string $dir = 'asc'): array
    {
        $result = $this->adminGameListQuery->find($page, $perPage, $search, $availability, $yamlReady, $apworldReady, $sort, $dir);
        $totalPages = $result['total'] > 0 ? (int) ceil($result['total'] / $perPage) : 1;

        // Story 9.38: join the upload-time preflight verdict by apworld hash. One runner
        // call for the whole page; on runner error the verdicts are simply absent (fail open).
        $items = $result['items'];
        if ([] !== array_filter($items, static fn (array $item): bool => is_string($item['apworldHash'] ?? null))) {
            $verdicts = $this->runnerGateway->fetchApworldPreflights();
            foreach ($items as $i => $item) {
                $hash = $item['apworldHash'] ?? null;
                $verdict = is_string($hash) ? ($verdicts[$hash] ?? null) : null;
                // An empty status means "never checked" - present it as no verdict at all.
                $items[$i]['apworldPreflight'] = (null !== $verdict && '' !== $verdict['status']) ? $verdict : null;
            }
        }

        return [
            'items' => $items,
            'total' => $result['total'],
            'page' => $page,
            'perPage' => $perPage,
            'totalPages' => $totalPages,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function detail(string $gameId): ?array
    {
        $game = $this->gameRepository->findById($gameId);
        if (!$game instanceof Game) {
            return null;
        }

        $payload = $this->detailPayload($game);
        $payload['apworldPreflight'] = $this->preflightForGame($game);

        return $payload;
    }

    /** A template is a whole YAML file with comments: 64 KB is generous and still bounded. */
    private const int DEFAULT_YAML_MAX_BYTES = 65536;

    /**
     * Replace the default YAML template served to players (story 9.45).
     *
     * The stored copy next to the apworld is updated too, because the upload preflight reads
     * THAT file: without the sync, a template an admin just fixed would keep failing its own
     * check. A runner outage never blocks the player-facing save - it only leaves the verdict
     * stale, which the caller reports.
     *
     * @return array{found: bool, errors: array<string, list<string>>, game?: array<string, mixed>, warning?: string}
     */
    public function saveDefaultYaml(string $gameId, string $defaultYaml): array
    {
        $game = $this->gameRepository->findById($gameId);
        if (!$game instanceof Game) {
            return ['found' => false, 'errors' => []];
        }

        $errors = $this->validateDefaultYaml($game, $defaultYaml);
        if ([] !== $errors) {
            return ['found' => true, 'errors' => $errors];
        }

        $game->overrideDefaultYaml($defaultYaml, $this->clock->now());
        $this->gameRepository->save($game);

        $warning = null;
        $hash = $game->getApworldHash();
        if (null !== $hash && '' !== $hash) {
            if ($this->runnerGateway->setApworldTemplate($hash, $defaultYaml)) {
                // The verdict must describe the template we now serve, not the previous one.
                $this->runnerGateway->runApworldPreflight($hash);
            } else {
                $warning = 'Template enregistré, mais le runner est indisponible : le verdict du test reste celui de la version précédente.';
            }
        }

        $this->logger->info('game.default_yaml_edited', ['gameId' => $gameId, 'bytes' => \strlen($defaultYaml)]);

        $payload = ['found' => true, 'errors' => [], 'game' => $this->detailPayload($game)];

        return null !== $warning ? [...$payload, 'warning' => $warning] : $payload;
    }

    /**
     * Regenerate the template from the stored apworld (story 9.46): undoes an edit, and
     * repairs a game whose template failed at upload. A world that still cannot produce a
     * template leaves the stored value untouched and returns the actionable error.
     *
     * @return array{found: bool, errors: array<string, list<string>>, game?: array<string, mixed>}
     */
    public function regenerateDefaultYaml(string $gameId): array
    {
        $game = $this->gameRepository->findById($gameId);
        if (!$game instanceof Game) {
            return ['found' => false, 'errors' => []];
        }

        $hash = $game->getApworldHash();
        if (null === $hash || '' === $hash) {
            return ['found' => true, 'errors' => ['apworld' => ['Ce jeu n\'a pas d\'apworld configuré.']]];
        }

        $result = $this->runnerGateway->regenerateApworldTemplate($hash);
        $template = $result['template'] ?? null;
        if (!is_string($template) || '' === trim($template)) {
            $detail = is_string($result['error'] ?? null) ? GenerationFailureParser::summarize($result['error']) : '';

            return ['found' => true, 'errors' => ['apworld' => [
                '' !== $detail
                    ? 'La régénération a échoué : '.$detail
                    : 'La régénération a échoué : cet apworld ne produit pas de template.',
            ]]];
        }

        $game->overrideDefaultYaml($template, $this->clock->now());
        // The regenerated template may expose different options/locations than the edited one.
        $game->recordOptionTypes($this->runnerGateway->fetchOptionTypes($hash));
        $game->recordLocationNames($this->runnerGateway->fetchLocationNames($hash));
        $this->gameRepository->save($game);

        $this->runnerGateway->runApworldPreflight($hash);

        $this->logger->info('game.default_yaml_regenerated', ['gameId' => $gameId, 'hash' => $hash]);

        return ['found' => true, 'errors' => [], 'game' => $this->detailPayload($game)];
    }

    /**
     * @return array<string, list<string>>
     */
    private function validateDefaultYaml(Game $game, string $defaultYaml): array
    {
        $errors = new ValidationErrors();

        if ('' === trim($defaultYaml)) {
            $errors->add('defaultYaml', 'Le template ne peut pas être vide.');

            return $errors->toArray();
        }

        if (\strlen($defaultYaml) > self::DEFAULT_YAML_MAX_BYTES) {
            $errors->add('defaultYaml', sprintf('Le template dépasse la taille maximale (%d Ko).', self::DEFAULT_YAML_MAX_BYTES / 1024));

            return $errors->toArray();
        }

        try {
            $parsed = Yaml::parse($defaultYaml);
        } catch (ParseException $e) {
            $errors->add('defaultYaml', 'YAML invalide : '.$e->getMessage());

            return $errors->toArray();
        }

        $game_ = is_array($parsed) && is_string($parsed['game'] ?? null) ? $parsed['game'] : null;
        if (null === $game_) {
            $errors->add('defaultYaml', 'Le template doit contenir un champ "game".');

            return $errors->toArray();
        }

        $expected = $game->getArchipelagoGameName();
        if (null !== $expected && '' !== $expected && $game_ !== $expected) {
            $errors->add('defaultYaml', sprintf('Le champ "game" doit valoir "%s" (trouvé "%s").', $expected, $game_));
        }

        return $errors->toArray();
    }

    /**
     * Re-run the upload-time preflight test generation of this game's apworld (story 9.38).
     *
     * @return array{found: bool, errors: array<string, list<string>>}
     */
    public function rerunApworldPreflight(string $gameId): array
    {
        $game = $this->gameRepository->findById($gameId);
        if (!$game instanceof Game) {
            return ['found' => false, 'errors' => []];
        }

        $hash = $game->getApworldHash();
        if (null === $hash || '' === $hash) {
            return ['found' => true, 'errors' => ['apworld' => ['Ce jeu n\'a pas d\'apworld configuré.']]];
        }

        if (!$this->runnerGateway->runApworldPreflight($hash)) {
            return ['found' => true, 'errors' => ['apworld' => ['Le runner est indisponible, réessaie plus tard.']]];
        }

        $this->logger->info('game.apworld_preflight_rerun', ['gameId' => $gameId, 'hash' => $hash]);

        return ['found' => true, 'errors' => []];
    }

    /**
     * Toggle the "force allow" override on this game's apworld preflight verdict (story 9.38 AC4).
     *
     * @return array{found: bool, errors: array<string, list<string>>, preflight?: array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool}}
     */
    public function overrideApworldPreflight(string $gameId, bool $overridden): array
    {
        $game = $this->gameRepository->findById($gameId);
        if (!$game instanceof Game) {
            return ['found' => false, 'errors' => []];
        }

        $hash = $game->getApworldHash();
        if (null === $hash || '' === $hash) {
            return ['found' => true, 'errors' => ['apworld' => ['Ce jeu n\'a pas d\'apworld configuré.']]];
        }

        $verdict = $this->runnerGateway->overrideApworldPreflight($hash, $overridden);
        if (null === $verdict) {
            return ['found' => true, 'errors' => ['apworld' => ['Le runner est indisponible, réessaie plus tard.']]];
        }

        $this->logger->info('game.apworld_preflight_override', ['gameId' => $gameId, 'hash' => $hash, 'overridden' => $overridden]);

        return ['found' => true, 'errors' => [], 'preflight' => $verdict];
    }

    /**
     * @return array{status: string, error: string, checkedAt: string, overridden: bool, blocks: bool}|null
     */
    private function preflightForGame(Game $game): ?array
    {
        $hash = $game->getApworldHash();
        if (null === $hash || '' === $hash) {
            return null;
        }

        $verdict = $this->runnerGateway->fetchApworldPreflights()[$hash] ?? null;

        return (null !== $verdict && '' !== $verdict['status']) ? $verdict : null;
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{game?: array<string, mixed>, errors: array<string, list<string>>}
     */
    public function create(array $input): array
    {
        $parsed = $this->parse($input);
        $errors = $this->validate($parsed);
        $this->validateUniqueSlug($parsed['slug'], null, $errors);

        if ([] !== $errors) {
            return ['errors' => $errors];
        }

        $game = Game::create(
            $parsed['name'],
            $parsed['slug'],
            $parsed['description'],
            $parsed['coverImageUrl'],
            $parsed['coverImageAlt'],
            $parsed['coverImageCredit'],
            $parsed['availability'],
            $this->clock->now(),
        );

        $catalogParsed = $this->parseCatalogSync($input);
        if ($this->hasCatalogSyncData($catalogParsed)) {
            $sync = new GameCatalogSync($game);
            $sync->update($catalogParsed['catalogSheetName'], $catalogParsed['apworldSourceUrl'], $catalogParsed['apworldDeployedVersion'], $catalogParsed['igdbId']);
            $game->attachCatalogSync($sync);
        }

        if (null !== $game->getIgdbId()) {
            $this->platformResolver->resolve($game);
        }

        $this->gameRepository->save($game);

        $this->logger->info('game.created', ['gameId' => $game->getId(), 'name' => $game->getName()]);

        return ['game' => $this->detailPayload($game), 'errors' => []];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{found: bool, game?: array<string, mixed>, errors: array<string, list<string>>}
     */
    public function update(string $gameId, array $input): array
    {
        $game = $this->gameRepository->findById($gameId);
        if (!$game instanceof Game) {
            return ['found' => false, 'errors' => []];
        }

        $parsed = $this->parse($input);
        $errors = $this->validate($parsed);
        $this->validateUniqueSlug($parsed['slug'], $game->getId(), $errors);

        if ([] !== $errors) {
            return ['found' => true, 'errors' => $errors];
        }

        $game->update(
            $parsed['name'],
            $parsed['slug'],
            $parsed['description'],
            $parsed['coverImageUrl'],
            $parsed['coverImageAlt'],
            $parsed['coverImageCredit'],
            $parsed['availability'],
            $this->clock->now(),
        );

        // Only touched when the caller actually sent the key: other PATCH callers (the catalogue
        // section) re-send a partial field set, and applying a missing key would wipe the field.
        if (array_key_exists('archipelagoDescription', $input)) {
            $game->recordArchipelagoDescription($parsed['archipelagoDescription']);
        }

        if (true === $parsed['availabilityLocked']) {
            $game->lockAvailability();
        } elseif (false === $parsed['availabilityLocked']) {
            $game->unlockAvailability();
        }

        // Kill switch (story 11.4): tri-state like availabilityLocked - absent key leaves the
        // state untouched, so partial PATCH callers cannot re-enable a game by accident.
        if (true === $parsed['disabled']) {
            $game->disable(
                array_key_exists('disabledMessage', $input) ? $parsed['disabledMessage'] : $game->getDisabledMessage(),
                $this->clock->now(),
            );
        } elseif (false === $parsed['disabled']) {
            $game->enable();
        } elseif ($game->isDisabled() && array_key_exists('disabledMessage', $input)) {
            $game->disable($parsed['disabledMessage'], $this->clock->now());
        }

        $previousIgdbId = $game->getIgdbId();

        $catalogParsed = $this->parseCatalogSync($input);
        $sync = $game->getCatalogSync();
        if (null === $sync) {
            if ($this->hasCatalogSyncData($catalogParsed)) {
                $sync = new GameCatalogSync($game);
                $sync->update($catalogParsed['catalogSheetName'], $catalogParsed['apworldSourceUrl'], $catalogParsed['apworldDeployedVersion'], $catalogParsed['igdbId']);
                $game->attachCatalogSync($sync);
            }
        } else {
            // PATCH semantics: only overwrite a catalog-sync field when its key is present in the
            // request. The edit form does not round-trip apworld_deployed_version / igdb_id, so a
            // full overwrite would silently wipe them (and the apworld update status / IGDB-derived
            // steam & platforms that depend on them). Absent keys keep their stored value; a key
            // present but empty still clears intentionally.
            $sync->update(
                array_key_exists('catalog_sheet_name', $input) ? $catalogParsed['catalogSheetName'] : $sync->getCatalogSheetName(),
                array_key_exists('apworld_source_url', $input) ? $catalogParsed['apworldSourceUrl'] : $sync->getApworldSourceUrl(),
                array_key_exists('apworld_deployed_version', $input) ? $catalogParsed['apworldDeployedVersion'] : $sync->getApworldDeployedVersion(),
                array_key_exists('igdb_id', $input) ? $catalogParsed['igdbId'] : $sync->getIgdbId(),
            );
        }

        // Re-resolve IGDB platforms when the link changed to a new (non-null) igdbId, so the
        // catalog/detail platforms stay correct without waiting for the bulk backfill. Clearing
        // the link is left untouched (conservative - no surprise platform wipe on save).
        $newIgdbId = $game->getIgdbId();
        if (null !== $newIgdbId && $newIgdbId !== $previousIgdbId) {
            $this->platformResolver->resolve($game);
        }

        $this->gameRepository->save($game);

        $this->logger->info('game.updated', ['gameId' => $gameId]);

        return ['found' => true, 'game' => $this->detailPayload($game), 'errors' => []];
    }

    /**
     * @return array{found: bool, game?: array<string, mixed>, errors: array<string, list<string>>}
     */
    public function configureApworld(string $gameId, string $fileContents, string $filename): array
    {
        $game = $this->gameRepository->findById($gameId);
        if (!$game instanceof Game) {
            return ['found' => false, 'errors' => []];
        }

        $errors = new ValidationErrors();

        if ('apworld' !== pathinfo($filename, PATHINFO_EXTENSION)) {
            $errors->add('file', 'Le fichier doit avoir l\'extension .apworld.');
        }

        if ('' === $fileContents) {
            $errors->add('file', 'Le fichier est vide.');
        }

        if ([] !== $errors->toArray()) {
            return ['found' => true, 'errors' => $errors->toArray()];
        }

        $result = $this->runnerGateway->uploadApworld($fileContents, $filename);

        if (isset($result['error'])) {
            $detail = is_string($result['detail'] ?? null) ? $result['detail'] : null;
            $message = match ($result['error']) {
                'runner_unavailable' => 'Le runner est indisponible.',
                'invalid_file' => 'Le fichier n\'est pas un .apworld valide.',
                'invalid_apworld' => $detail ?? 'Le fichier .apworld est invalide (archipelago.json manquant ou corrompu).',
                'template_timeout' => 'La génération du template a expiré - le runner est peut-être surchargé.',
                'template_failed' => 'ArchipelagoGenerate a échoué'.(null !== $detail && '' !== $detail ? " : {$detail}" : '.'),
                'archigenerate_not_found' => 'ArchipelagoGenerate est introuvable dans le runner. Configurez ARCHIPELAGO_GENERATE_CMD.',
                default => $detail ?? 'Erreur runner : '.(is_string($result['error']) ? $result['error'] : ''),
            };
            $this->logger->error('runner.apworld_upload_failed', ['gameId' => $gameId, 'error' => $result['error'], 'detail' => $detail]);

            return ['found' => true, 'errors' => ['file' => [$message]]];
        }

        $storageKey = is_string($result['storageKey'] ?? null) ? $result['storageKey'] : '';
        $hash = is_string($result['hash'] ?? null) ? $result['hash'] : '';
        $archipelagoGameName = is_string($result['archipelagoGameName'] ?? null) ? $result['archipelagoGameName'] : '';
        $defaultYaml = is_string($result['defaultYaml'] ?? null) ? $result['defaultYaml'] : '';

        if ('' === $storageKey || '' === $hash || '' === $archipelagoGameName) {
            return ['found' => true, 'errors' => ['file' => ['Le runner est indisponible ou le fichier .apworld est invalide.']]];
        }

        $minioKey = $hash.'.apworld';

        try {
            if (!$this->minioStorage->exists($this->minioApworldsBucket, $minioKey)) {
                $this->minioStorage->upload($this->minioApworldsBucket, $minioKey, $fileContents);
            }
        } catch (\Throwable $e) {
            $this->logger->error('minio.apworld_upload_failed', [
                'gameId' => $gameId,
                'hash' => $hash,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return ['found' => true, 'errors' => ['file' => ['storage_unavailable']]];
        }

        $game->configureApworld($storageKey, $hash, $archipelagoGameName, $defaultYaml, $this->clock->now());
        $game->recordApworldMinioUpload($minioKey);
        $game->recordOptionTypes(self::normalizeOptionTypes($result['optionTypes'] ?? null));
        $game->recordLocationNames(self::normalizeLocationNames($result['locationNames'] ?? null));
        $this->gameRepository->save($game);

        $this->logger->info('game.apworld_configured', ['gameId' => $gameId, 'hash' => $hash, 'archipelagoGameName' => $archipelagoGameName]);

        return ['found' => true, 'game' => $this->detailPayload($game), 'errors' => []];
    }

    /**
     * @return array{found: bool, assets?: list<array{name: string, downloadUrl: string, size: int, tag: ?string}>, errors: array<string, list<string>>}
     */
    public function listGithubAssets(string $gameId): array
    {
        $game = $this->gameRepository->findById($gameId);
        if (!$game instanceof Game) {
            return ['found' => false, 'errors' => []];
        }

        $sourceUrl = $game->getCatalogSync()?->getApworldSourceUrl();

        if (null === $sourceUrl) {
            return ['found' => true, 'errors' => ['github' => ['Aucune URL source APWorld configurée pour ce jeu.']]];
        }

        if (!$this->apworldVersionChecker->isAvailable() && !$this->apworldVersionChecker->isDirectApworldUrl($sourceUrl)) {
            return ['found' => true, 'errors' => ['github' => ['GITHUB_TOKEN non configuré.']]];
        }

        try {
            $assets = $this->apworldVersionChecker->listAssets($game);
        } catch (\Throwable $e) {
            return ['found' => true, 'errors' => ['github' => ['Impossible de contacter la source : '.$e->getMessage()]]];
        }

        if (null === $assets) {
            return ['found' => true, 'errors' => ['github' => ['Aucune release trouvée pour cette URL.']]];
        }

        return ['found' => true, 'assets' => $assets, 'errors' => []];
    }

    /**
     * @return array{found: bool, game?: array<string, mixed>, errors: array<string, list<string>>}
     */
    public function importFromGithub(string $gameId, ?string $assetDownloadUrl = null, ?string $assetName = null, ?string $assetTag = null): array
    {
        $game = $this->gameRepository->findById($gameId);
        if (!$game instanceof Game) {
            return ['found' => false, 'errors' => []];
        }

        $sourceUrl = $game->getCatalogSync()?->getApworldSourceUrl();

        if (null === $sourceUrl || '' === $sourceUrl) {
            return ['found' => true, 'errors' => ['github' => ['Aucune URL source APWorld configurée pour ce jeu.']]];
        }

        if (!$this->apworldVersionChecker->isAvailable() && !$this->apworldVersionChecker->isDirectApworldUrl($sourceUrl)) {
            return ['found' => true, 'errors' => ['github' => ['GITHUB_TOKEN non configuré - impossible de télécharger depuis GitHub.']]];
        }

        // If a specific asset was pre-selected by the caller, skip the GitHub check.
        // The caller passes the release tag alongside the asset (see listAssets), so the
        // deployed version is still recorded without a second GitHub round-trip.
        if (null !== $assetDownloadUrl && null !== $assetName) {
            $resolvedDownloadUrl = $assetDownloadUrl;
            $resolvedAssetName = $assetName;
            $latestTag = '' !== (string) $assetTag ? $assetTag : null;
        } else {
            try {
                $info = $this->apworldVersionChecker->check($game);
                $this->gameRepository->save($game);
            } catch (\Throwable $e) {
                $this->logger->error('github.check_failed', ['gameId' => $gameId, 'message' => $e->getMessage()]);

                return ['found' => true, 'errors' => ['github' => ['Impossible de contacter GitHub : '.$e->getMessage()]]];
            }

            if (null === $info) {
                return ['found' => true, 'errors' => ['github' => ['Aucune release trouvée pour cette URL GitHub.']]];
            }

            if (null === $info->assetDownloadUrl || null === $info->assetName) {
                return ['found' => true, 'errors' => ['github' => ['Aucun asset .apworld trouvé dans la dernière release ('.$info->latestTag.').']]];
            }

            $resolvedDownloadUrl = $info->assetDownloadUrl;
            $resolvedAssetName = $info->assetName;
            $latestTag = $info->latestTag;
        }

        try {
            $fileContents = $this->apworldVersionChecker->downloadAsset($resolvedDownloadUrl);
        } catch (\Throwable $e) {
            $this->logger->error('github.download_failed', ['gameId' => $gameId, 'url' => $resolvedDownloadUrl, 'message' => $e->getMessage()]);

            return ['found' => true, 'errors' => ['github' => ['Échec du téléchargement de l\'asset : '.$e->getMessage()]]];
        }

        $result = $this->configureApworld($gameId, $fileContents, $resolvedAssetName);

        if ([] !== $result['errors']) {
            return $result;
        }

        if (null !== $latestTag) {
            $game->getCatalogSync()?->recordApworldDeployment($latestTag);
            $this->gameRepository->save($game);
            // configureApworld built the returned payload before the deployed version was set;
            // rebuild it so the response reflects the freshly recorded version.
            $result['game'] = $this->detailPayload($game);
        }

        $this->logger->info('game.apworld_imported_from_github', [
            'gameId' => $gameId,
            'tag' => $latestTag,
            'asset' => $resolvedAssetName,
        ]);

        return $result;
    }

    /**
     * @return array{found: bool, errors: array<string, list<string>>}
     */
    public function remove(string $gameId): array
    {
        $game = $this->gameRepository->findById($gameId);
        if (!$game instanceof Game) {
            return ['found' => false, 'errors' => []];
        }

        if ($this->usageCount($game) > 0) {
            return ['found' => true, 'errors' => ['game' => ['Ce jeu est déjà utilisé et ne peut pas être supprimé.']]];
        }

        $this->gameRepository->remove($game);

        $this->logger->info('game.removed', ['gameId' => $gameId]);

        return ['found' => true, 'errors' => []];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{name: string, slug: string, description: string, archipelagoDescription: ?string, coverImageUrl: ?string, coverImageAlt: string, coverImageCredit: string, availability: string, availabilityLocked: bool|null, disabled: bool|null, disabledMessage: ?string}
     */
    private function parse(array $input): array
    {
        $rawCoverImageUrl = $input['coverImageUrl'] ?? null;

        return [
            'name' => is_string($input['name'] ?? null) ? trim($input['name']) : '',
            'slug' => is_string($input['slug'] ?? null) ? Game::normalizeSlug($input['slug']) : '',
            'description' => is_string($input['description'] ?? null) ? trim($input['description']) : '',
            'archipelagoDescription' => is_string($input['archipelagoDescription'] ?? null) ? trim($input['archipelagoDescription']) : null,
            'coverImageUrl' => is_string($rawCoverImageUrl) && '' !== trim($rawCoverImageUrl) ? trim($rawCoverImageUrl) : null,
            'coverImageAlt' => is_string($input['coverImageAlt'] ?? null) ? trim($input['coverImageAlt']) : '',
            'coverImageCredit' => is_string($input['coverImageCredit'] ?? null) ? trim($input['coverImageCredit']) : '',
            'availability' => is_string($input['availability'] ?? null) ? trim($input['availability']) : '',
            'availabilityLocked' => isset($input['availability_locked']) ? (bool) $input['availability_locked'] : null,
            'disabled' => isset($input['disabled']) ? (bool) $input['disabled'] : null,
            'disabledMessage' => is_string($input['disabledMessage'] ?? null) ? trim($input['disabledMessage']) : null,
        ];
    }

    /**
     * @param array{name: string, slug: string, description: string, archipelagoDescription: ?string, coverImageUrl: ?string, coverImageAlt: string, coverImageCredit: string, availability: string, disabledMessage: ?string} $input
     *
     * @return array<string, list<string>>
     */
    private function validate(array $input): array
    {
        $errors = new ValidationErrors();

        foreach (['name' => 'Le nom est requis.', 'slug' => 'Le slug est requis.', 'description' => 'La description est requise.'] as $field => $message) {
            if ('' === $input[$field]) {
                $errors->add($field, $message);
            }
        }

        // Was unbounded TEXT; markdown makes long input more attractive, so cap it (story 10.10).
        if (mb_strlen($input['description']) > self::MAX_DESCRIPTION) {
            $errors->add('description', sprintf('La description ne peut pas dépasser %d caractères.', self::MAX_DESCRIPTION));
        }

        if (null !== $input['archipelagoDescription'] && mb_strlen($input['archipelagoDescription']) > self::MAX_DESCRIPTION) {
            $errors->add('archipelagoDescription', sprintf('La description Archipelago ne peut pas dépasser %d caractères.', self::MAX_DESCRIPTION));
        }

        if ('' !== $input['slug'] && 1 !== preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $input['slug'])) {
            $errors->add('slug', 'Le slug doit contenir seulement des minuscules, chiffres et tirets.');
        }

        if (!in_array($input['availability'], Game::supportedAvailabilities(), true)) {
            $errors->add('availability', 'État de disponibilité invalide.');
        }

        if (null !== $input['disabledMessage'] && mb_strlen($input['disabledMessage']) > 500) {
            $errors->add('disabledMessage', 'Le message de désactivation ne peut pas dépasser 500 caractères.');
        }

        return $errors->toArray();
    }

    /**
     * @param array<string, list<string>> $errors
     */
    private function validateUniqueSlug(string $slug, ?string $currentGameId, array &$errors): void
    {
        if ('' === $slug) {
            return;
        }

        $existing = $this->gameRepository->findBySlug($slug);

        if ($existing instanceof Game && $existing->getId() !== $currentGameId) {
            $errors['slug'][] = 'Ce slug est déjà utilisé.';
        }
    }

    private function usageCount(Game $game): int
    {
        return $this->gameUsageCounter->count($game->getId());
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(Game $game): array
    {
        return [
            'id' => $game->getId(),
            'name' => $game->getName(),
            'slug' => $game->getSlug(),
            'description' => $game->getDescription(),
            'archipelagoDescription' => $game->getArchipelagoDescription(),
            'coverImageUrl' => $game->getCoverImageUrl(),
            'coverImageAlt' => $game->getCoverImageAlt(),
            'coverImageCredit' => $game->getCoverImageCredit(),
            'availability' => $game->getAvailability(),
            'disabled' => $game->isDisabled(),
            'disabledMessage' => $game->getDisabledMessage(),
            'archipelagoGameName' => $game->getArchipelagoGameName(),
            'isYamlReady' => $game->isYamlReady(),
            'isApworldReady' => $game->isApworldReady(),
            'apworldHash' => $game->getApworldHash(),
            'apworldUploadedAt' => $game->getApworldUploadedAt()?->format(\DateTimeInterface::ATOM),
            'usageCount' => $this->usageCount($game),
            'createdAt' => $game->getCreatedAt()->format(\DateTimeInterface::ATOM),
            'updatedAt' => $game->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function detailPayload(Game $game): array
    {
        $sync = $game->getCatalogSync();

        return array_merge($this->payload($game), [
            'defaultYaml' => $game->getDefaultYaml(),
            'optionTypes' => $game->getOptionTypes(),
            'locationNames' => $game->getLocationNames(),
            'adminNotes' => $game->getAdminNotes(),
            'catalogSheetName' => $sync?->getCatalogSheetName(),
            'apworldSourceUrl' => $sync?->getApworldSourceUrl(),
            'apworldDeployedVersion' => $sync?->getApworldDeployedVersion(),
            'apworldLatestVersion' => $sync?->getApworldLatestVersion(),
            'apworldCheckedAt' => $sync?->getApworldCheckedAt()?->format(\DateTimeInterface::ATOM),
            'apworldReleaseUrl' => $sync?->getApworldReleaseUrl(),
            'availabilityLocked' => $game->isAvailabilityLocked(),
            'igdbId' => $sync?->getIgdbId(),
            'steamAppId' => $sync?->getSteamAppId(),
            'platforms' => PlatformCategory::families($game->getPlatforms() ?? []),
            'installSteps' => $this->stepsReader->present($game->getInstallSteps()),
            'updateStatus' => $game->computeApworldUpdateStatus(),
        ]);
    }

    /**
     * Force a re-resolution of the game's IGDB platforms (curated families), independent of any
     * field change. Used by the "Synchroniser depuis IGDB" control on the admin editor.
     *
     * @return array{found: bool, game?: array<string, mixed>, errors: array<string, list<string>>}
     */
    public function resyncPlatforms(string $gameId): array
    {
        $game = $this->gameRepository->findById($gameId);
        if (!$game instanceof Game) {
            return ['found' => false, 'errors' => []];
        }

        if (null === $game->getIgdbId()) {
            return ['found' => true, 'errors' => ['platforms' => ['Aucun identifiant IGDB associé à ce jeu.']]];
        }

        if (!$this->platformResolver->resolve($game)) {
            return ['found' => true, 'errors' => ['platforms' => ['Impossible de contacter IGDB pour le moment.']]];
        }

        $this->gameRepository->save($game);

        $this->logger->info('game.platforms_resynced', ['gameId' => $gameId]);

        return ['found' => true, 'game' => $this->detailPayload($game), 'errors' => []];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{catalogSheetName: ?string, apworldSourceUrl: ?string, apworldDeployedVersion: ?string, igdbId: ?int}
     */
    private function parseCatalogSync(array $input): array
    {
        $catalogSheetName = is_string($input['catalog_sheet_name'] ?? null) && '' !== trim($input['catalog_sheet_name'])
            ? trim($input['catalog_sheet_name'])
            : null;

        $rawSourceUrl = is_string($input['apworld_source_url'] ?? null) ? trim($input['apworld_source_url']) : '';
        $apworldSourceUrl = '' !== $rawSourceUrl
            ? (Game::normalizeApworldSourceUrl($rawSourceUrl) ?? $rawSourceUrl)
            : null;

        $apworldDeployedVersion = is_string($input['apworld_deployed_version'] ?? null) && '' !== trim($input['apworld_deployed_version'])
            ? trim($input['apworld_deployed_version'])
            : null;

        $igdbId = is_int($input['igdb_id'] ?? null) ? $input['igdb_id'] : null;

        return [
            'catalogSheetName' => $catalogSheetName,
            'apworldSourceUrl' => $apworldSourceUrl,
            'apworldDeployedVersion' => $apworldDeployedVersion,
            'igdbId' => $igdbId,
        ];
    }

    /**
     * @param array{catalogSheetName: ?string, apworldSourceUrl: ?string, apworldDeployedVersion: ?string, igdbId: ?int} $parsed
     */
    private function hasCatalogSyncData(array $parsed): bool
    {
        return null !== $parsed['catalogSheetName']
            || null !== $parsed['apworldSourceUrl']
            || null !== $parsed['igdbId'];
    }

    /**
     * @return list<string>
     */
    private static function normalizeLocationNames(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $names = [];
        foreach ($raw as $name) {
            if (is_string($name) && '' !== $name) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * @return array<string, array{min: int, max: int, default: int|null}>
     */
    private static function normalizeOptionTypes(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $types = [];
        foreach ($raw as $key => $bounds) {
            if (!is_string($key) || !is_array($bounds)) {
                continue;
            }
            $min = $bounds['min'] ?? null;
            $max = $bounds['max'] ?? null;
            if (!is_int($min) || !is_int($max)) {
                continue;
            }
            $default = $bounds['default'] ?? null;
            $types[$key] = ['min' => $min, 'max' => $max, 'default' => is_int($default) ? $default : null];
        }

        return $types;
    }
}
