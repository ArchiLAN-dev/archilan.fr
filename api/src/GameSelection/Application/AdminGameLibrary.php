<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

use App\CatalogSync\Application\ApworldVersionChecker;
use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameCatalogSync;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\Identity\Application\ValidationErrors;
use App\Sessions\Application\RunnerGatewayInterface;
use App\Shared\Infrastructure\MinioStorageInterface;
use Psr\Log\LoggerInterface;

final readonly class AdminGameLibrary
{
    public function __construct(
        private GameRepositoryInterface $gameRepository,
        private AdminGameListQueryInterface $adminGameListQuery,
        private LoggerInterface $logger,
        private RunnerGatewayInterface $runnerGateway,
        private MinioStorageInterface $minioStorage,
        private string $minioApworldsBucket,
        private ApworldVersionChecker $apworldVersionChecker,
        private GameUsageCounterInterface $gameUsageCounter,
    ) {
    }

    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function list(int $page = 1, int $perPage = 50, string $search = '', ?string $availability = null, ?bool $yamlReady = null, ?bool $apworldReady = null, string $sort = 'name', string $dir = 'asc'): array
    {
        $result = $this->adminGameListQuery->find($page, $perPage, $search, $availability, $yamlReady, $apworldReady, $sort, $dir);
        $totalPages = $result['total'] > 0 ? (int) ceil($result['total'] / $perPage) : 1;

        return [
            'items' => $result['items'],
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

        return $game instanceof Game ? $this->detailPayload($game) : null;
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
            new \DateTimeImmutable(),
        );

        $catalogParsed = $this->parseCatalogSync($input);
        if ($this->hasCatalogSyncData($catalogParsed)) {
            $sync = new GameCatalogSync($game);
            $sync->update($catalogParsed['catalogSheetName'], $catalogParsed['apworldSourceUrl'], $catalogParsed['apworldDeployedVersion'], $catalogParsed['igdbId']);
            $game->setCatalogSync($sync);
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
            new \DateTimeImmutable(),
        );

        if (null !== $parsed['availabilityLocked']) {
            $game->setAvailabilityLocked($parsed['availabilityLocked']);
        }

        $catalogParsed = $this->parseCatalogSync($input);
        $sync = $game->getCatalogSync();
        if (null === $sync) {
            if ($this->hasCatalogSyncData($catalogParsed)) {
                $sync = new GameCatalogSync($game);
                $sync->update($catalogParsed['catalogSheetName'], $catalogParsed['apworldSourceUrl'], $catalogParsed['apworldDeployedVersion'], $catalogParsed['igdbId']);
                $game->setCatalogSync($sync);
            }
        } else {
            $sync->update($catalogParsed['catalogSheetName'], $catalogParsed['apworldSourceUrl'], $catalogParsed['apworldDeployedVersion'], $catalogParsed['igdbId']);
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
                'template_failed' => 'ArchipelagoGenerate a échoué'.($detail ? " : {$detail}" : '.'),
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

        $game->configureApworld($storageKey, $hash, $archipelagoGameName, $defaultYaml, new \DateTimeImmutable());
        $game->setApworldMinioKey($minioKey);
        $game->setOptionTypes(self::normalizeOptionTypes($result['optionTypes'] ?? null));
        $this->gameRepository->save($game);

        $this->logger->info('game.apworld_configured', ['gameId' => $gameId, 'hash' => $hash, 'archipelagoGameName' => $archipelagoGameName]);

        return ['found' => true, 'game' => $this->detailPayload($game), 'errors' => []];
    }

    /**
     * @return array{found: bool, assets?: list<array{name: string, downloadUrl: string, size: int}>, errors: array<string, list<string>>}
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
    public function importFromGithub(string $gameId, ?string $assetDownloadUrl = null, ?string $assetName = null): array
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

        // If a specific asset was pre-selected by the caller, skip the GitHub check
        if (null !== $assetDownloadUrl && null !== $assetName) {
            $resolvedDownloadUrl = $assetDownloadUrl;
            $resolvedAssetName = $assetName;
            $latestTag = null;
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
            $game->getCatalogSync()?->setApworldDeployedVersion($latestTag);
            $this->gameRepository->save($game);
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
     * @return array{name: string, slug: string, description: string, coverImageUrl: ?string, coverImageAlt: string, coverImageCredit: string, availability: string, availabilityLocked: bool|null}
     */
    private function parse(array $input): array
    {
        $rawCoverImageUrl = $input['coverImageUrl'] ?? null;

        return [
            'name' => is_string($input['name'] ?? null) ? trim($input['name']) : '',
            'slug' => is_string($input['slug'] ?? null) ? Game::normalizeSlug($input['slug']) : '',
            'description' => is_string($input['description'] ?? null) ? trim($input['description']) : '',
            'coverImageUrl' => is_string($rawCoverImageUrl) && '' !== trim($rawCoverImageUrl) ? trim($rawCoverImageUrl) : null,
            'coverImageAlt' => is_string($input['coverImageAlt'] ?? null) ? trim($input['coverImageAlt']) : '',
            'coverImageCredit' => is_string($input['coverImageCredit'] ?? null) ? trim($input['coverImageCredit']) : '',
            'availability' => is_string($input['availability'] ?? null) ? trim($input['availability']) : '',
            'availabilityLocked' => isset($input['availability_locked']) ? (bool) $input['availability_locked'] : null,
        ];
    }

    /**
     * @param array{name: string, slug: string, description: string, coverImageUrl: ?string, coverImageAlt: string, coverImageCredit: string, availability: string} $input
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

        if ('' !== $input['slug'] && !preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $input['slug'])) {
            $errors->add('slug', 'Le slug doit contenir seulement des minuscules, chiffres et tirets.');
        }

        if (!in_array($input['availability'], Game::supportedAvailabilities(), true)) {
            $errors->add('availability', 'État de disponibilité invalide.');
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
            'coverImageUrl' => $game->getCoverImageUrl(),
            'coverImageAlt' => $game->getCoverImageAlt(),
            'coverImageCredit' => $game->getCoverImageCredit(),
            'availability' => $game->getAvailability(),
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
            'catalogSheetName' => $sync?->getCatalogSheetName(),
            'apworldSourceUrl' => $sync?->getApworldSourceUrl(),
            'apworldDeployedVersion' => $sync?->getApworldDeployedVersion(),
            'apworldLatestVersion' => $sync?->getApworldLatestVersion(),
            'apworldCheckedAt' => $sync?->getApworldCheckedAt()?->format(\DateTimeInterface::ATOM),
            'apworldReleaseUrl' => $sync?->getApworldReleaseUrl(),
            'availabilityLocked' => $game->isAvailabilityLocked(),
            'igdbId' => $sync?->getIgdbId(),
            'steamAppId' => $sync?->getSteamAppId(),
            'updateStatus' => $game->computeApworldUpdateStatus(),
        ]);
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
