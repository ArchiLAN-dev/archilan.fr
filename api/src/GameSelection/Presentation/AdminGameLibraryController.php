<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation;

use App\GameSelection\Application\AdminGameLibrary;
use App\GameSelection\Domain\Game;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminGameLibraryController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminGameLibrary $adminGameLibrary,
    ) {
    }

    #[Route('/api/v1/admin/games', name: 'api_game_selection_admin_games_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $page = max(1, (int) $request->query->get('page', '1'));
        $perPage = min(200, max(1, (int) $request->query->get('per_page', '50')));
        $search = trim((string) $request->query->get('search', ''));

        $rawAvailability = $request->query->has('availability') ? (string) $request->query->get('availability') : null;
        $availability = null !== $rawAvailability && in_array($rawAvailability, Game::supportedAvailabilities(), true)
            ? $rawAvailability
            : null;

        $rawYamlReady = $request->query->has('yaml_ready') ? (string) $request->query->get('yaml_ready') : null;
        $yamlReady = match ($rawYamlReady) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => null,
        };

        $rawApworldReady = $request->query->has('apworld_ready') ? (string) $request->query->get('apworld_ready') : null;
        $apworldReady = match ($rawApworldReady) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => null,
        };

        $rawSort = (string) $request->query->get('sort', 'name');
        $sort = in_array($rawSort, ['name', 'usage'], true) ? $rawSort : 'name';

        $rawDir = strtolower((string) $request->query->get('dir', 'asc'));
        $dir = in_array($rawDir, ['asc', 'desc'], true) ? $rawDir : 'asc';

        $result = $this->adminGameLibrary->list($page, $perPage, $search, $availability, $yamlReady, $apworldReady, $sort, $dir);

        return new JsonResponse([
            'data' => $result['items'],
            'meta' => [
                'total' => $result['total'],
                'page' => $result['page'],
                'perPage' => $result['perPage'],
                'totalPages' => $result['totalPages'],
            ],
        ]);
    }

    #[Route('/api/v1/admin/games', name: 'api_game_selection_admin_games_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = $this->adminGameLibrary->create($this->jsonPayload($request));

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Le jeu contient des erreurs.', 422, $result['errors']);
        }

        $game = $result['game'] ?? null;
        if (null === $game) {
            return $this->apiAccessGuard->errorResponse('game_creation_failed', 'La création du jeu a échoué.', 500);
        }

        return new JsonResponse(['data' => $game, 'meta' => ['message' => 'Jeu créé.']], 201);
    }

    #[Route('/api/v1/admin/games/{gameId}', name: 'api_game_selection_admin_games_detail', methods: ['GET'])]
    public function detail(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = $this->adminGameLibrary->detail($gameId);

        if (null === $result) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Jeu introuvable.', 404);
        }

        return new JsonResponse(['data' => $result, 'meta' => []]);
    }

    #[Route('/api/v1/admin/games/{gameId}', name: 'api_game_selection_admin_games_update', methods: ['PATCH'])]
    public function update(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = $this->adminGameLibrary->update($gameId, $this->jsonPayload($request));

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Jeu introuvable.', 404);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Le jeu contient des erreurs.', 422, $result['errors']);
        }

        $game = $result['game'] ?? null;
        if (null === $game) {
            return $this->apiAccessGuard->errorResponse('game_update_failed', 'La mise à jour du jeu a échoué.', 500);
        }

        return new JsonResponse(['data' => $game, 'meta' => ['message' => 'Jeu mis à jour.']]);
    }

    #[Route('/api/v1/admin/games/{gameId}/tutorial', name: 'api_admin_game_save_tutorial', methods: ['PATCH'])]
    public function saveTutorial(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = $this->jsonPayload($request);
        $steps = is_array($payload['steps'] ?? null) ? $payload['steps'] : [];

        $result = $this->adminGameLibrary->saveTutorial($gameId, $steps);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Jeu introuvable.', 404);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Le tutoriel contient des erreurs.', 422, $result['errors']);
        }

        $game = $result['game'] ?? null;
        if (null === $game) {
            return $this->apiAccessGuard->errorResponse('tutorial_save_failed', 'L\'enregistrement du tutoriel a échoué.', 500);
        }

        return new JsonResponse(['data' => $game, 'meta' => ['message' => 'Tutoriel enregistré.']]);
    }

    #[Route('/api/v1/admin/games/{gameId}/tutorial/seed', name: 'api_admin_game_seed_tutorial', methods: ['POST'])]
    public function seedTutorial(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = $this->adminGameLibrary->seedTutorial($gameId, '1' === $request->query->get('force'));

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Jeu introuvable.', 404);
        }

        $game = $result['game'] ?? null;
        if (null === $game) {
            return $this->apiAccessGuard->errorResponse('tutorial_seed_failed', 'La génération du brouillon a échoué.', 500);
        }

        return new JsonResponse(['data' => $game, 'meta' => ['message' => 'Brouillon généré.']]);
    }

    #[Route('/api/v1/admin/games/{gameId}/resync-platforms', name: 'api_admin_game_resync_platforms', methods: ['POST'])]
    public function resyncPlatforms(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = $this->adminGameLibrary->resyncPlatforms($gameId);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Jeu introuvable.', 404);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('platforms_resync_failed', 'La synchronisation des plateformes a échoué.', 422, $result['errors']);
        }

        $game = $result['game'] ?? null;
        if (null === $game) {
            return $this->apiAccessGuard->errorResponse('platforms_resync_failed', 'La synchronisation des plateformes a échoué.', 500);
        }

        return new JsonResponse(['data' => $game, 'meta' => ['message' => 'Plateformes synchronisées.']]);
    }

    #[Route('/api/v1/admin/games/{gameId}/apworld', name: 'api_admin_game_configure_apworld', methods: ['PATCH'])]
    public function configureApworld(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Le fichier est requis.', 422, ['file' => ['Le fichier est requis.']]);
        }

        if (!$file->isValid()) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Le fichier est invalide ou trop volumineux.', 422, ['file' => [$file->getErrorMessage()]]);
        }

        $contents = file_get_contents($file->getPathname());

        if (false === $contents) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Le fichier est illisible.', 422, ['file' => ['Le fichier est illisible.']]);
        }

        $result = $this->adminGameLibrary->configureApworld($gameId, $contents, $file->getClientOriginalName());

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Jeu introuvable.', 404);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'La configuration du .apworld a échoué.', 422, $result['errors']);
        }

        $game = $result['game'] ?? null;
        if (null === $game) {
            return $this->apiAccessGuard->errorResponse('apworld_failed', 'La configuration du .apworld a échoué.', 500);
        }

        return new JsonResponse(['data' => $game, 'meta' => ['message' => '.apworld configuré.']]);
    }

    #[Route('/api/v1/admin/games/{gameId}/github-assets', name: 'api_admin_game_github_assets', methods: ['GET'])]
    public function listGithubAssets(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = $this->adminGameLibrary->listGithubAssets($gameId);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Jeu introuvable.', 404);
        }

        if ([] !== $result['errors']) {
            $firstError = array_values($result['errors'])[0][0] ?? 'Erreur.';

            return $this->apiAccessGuard->errorResponse('github_assets_failed', $firstError, 422);
        }

        return new JsonResponse(['data' => $result['assets'] ?? [], 'meta' => []]);
    }

    #[Route('/api/v1/admin/games/{gameId}/apworld-from-github', name: 'api_admin_game_apworld_from_github', methods: ['POST'])]
    public function importApworldFromGithub(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $body = $this->jsonPayload($request);
        $assetDownloadUrl = is_string($body['assetDownloadUrl'] ?? null) && '' !== $body['assetDownloadUrl'] ? $body['assetDownloadUrl'] : null;
        $assetName = is_string($body['assetName'] ?? null) && '' !== $body['assetName'] ? $body['assetName'] : null;
        $assetTag = is_string($body['assetTag'] ?? null) && '' !== $body['assetTag'] ? $body['assetTag'] : null;

        $result = $this->adminGameLibrary->importFromGithub($gameId, $assetDownloadUrl, $assetName, $assetTag);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Jeu introuvable.', 404);
        }

        if ([] !== $result['errors']) {
            $firstError = array_values($result['errors'])[0][0] ?? 'Erreur lors de l\'import.';

            return $this->apiAccessGuard->errorResponse('github_import_failed', $firstError, 422, $result['errors']);
        }

        $game = $result['game'] ?? null;
        if (null === $game) {
            return $this->apiAccessGuard->errorResponse('github_import_failed', 'L\'import a échoué.', 500);
        }

        return new JsonResponse(['data' => $game, 'meta' => ['message' => '.apworld importé depuis GitHub.']]);
    }

    #[Route('/api/v1/admin/games/{gameId}', name: 'api_game_selection_admin_games_delete', methods: ['DELETE'])]
    public function delete(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = $this->adminGameLibrary->remove($gameId);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Jeu introuvable.', 404);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('game_in_use', 'Le jeu ne peut pas être supprimé.', 409, $result['errors']);
        }

        return new JsonResponse(null, 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(Request $request): array
    {
        try {
            $payload = json_decode($request->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($payload)) {
            return [];
        }

        $normalized = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
