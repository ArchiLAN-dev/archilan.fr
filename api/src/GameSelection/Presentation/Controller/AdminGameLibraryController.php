<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation\Controller;

use App\GameSelection\Application\Service\AdminGameLibrary;
use App\GameSelection\Domain\Entity\Game;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
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
        $search = trim($request->query->get('search', ''));

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

        $rawSort = $request->query->get('sort', 'name');
        $sort = in_array($rawSort, ['name', 'usage'], true) ? $rawSort : 'name';

        $rawDir = strtolower($request->query->get('dir', 'asc'));
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

    #[Route('/api/v1/admin/games/{gameId}/notes', name: 'api_admin_game_save_notes', methods: ['PATCH'])]
    public function saveNotes(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = $this->jsonPayload($request);
        $raw = $payload['adminNotes'] ?? null;
        if (null !== $raw && !is_string($raw)) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Les notes doivent être du texte.', 422, ['adminNotes' => ['Format invalide.']]);
        }
        if (is_string($raw) && mb_strlen($raw) > 20000) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Les notes sont trop longues (max 20000 caractères).', 422, ['adminNotes' => ['Trop long (max 20000 caractères).']]);
        }

        $result = $this->adminGameLibrary->saveNotes($gameId, $raw);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Jeu introuvable.', 404);
        }

        $game = $result['game'] ?? null;
        if (null === $game) {
            return $this->apiAccessGuard->errorResponse('notes_save_failed', 'L\'enregistrement des notes a échoué.', 500);
        }

        return new JsonResponse(['data' => $game, 'meta' => ['message' => 'Notes enregistrées.']]);
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

    #[Route('/api/v1/admin/games/{gameId}/platforms', name: 'api_admin_game_save_platforms', methods: ['PUT'])]
    public function savePlatforms(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        $raw = is_array($payload) ? ($payload['platforms'] ?? null) : null;

        $families = null;
        if (is_array($raw)) {
            $families = [];
            foreach ($raw as $family) {
                if (is_string($family)) {
                    $families[] = $family;
                }
            }
        }

        $result = $this->adminGameLibrary->savePlatformFamilies($gameId, $families);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Jeu introuvable.', 404);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Plateformes invalides.', 422, $result['errors']);
        }

        $game = $result['game'] ?? null;
        if (null === $game) {
            return $this->apiAccessGuard->errorResponse('platforms_save_failed', 'L\'enregistrement a échoué.', 500);
        }

        return new JsonResponse(['data' => $game, 'meta' => ['message' => null === $families
            ? 'Plateformes IGDB rétablies.'
            : 'Plateformes enregistrées.']]);
    }

    /**
     * Curates what the sub-settings of one dict option accept (story 9.52).
     *
     * Body: `{"option": "game_options", "values": {"battle_style": {"values": ["shift", "set"], "closed": true}}}`.
     * An absent or empty `values` clears the curation for that option.
     */
    #[Route('/api/v1/admin/games/{gameId}/dict-option-values', name: 'api_admin_game_save_dict_option_values', methods: ['PUT'])]
    public function saveDictOptionValues(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        $payload = is_array($payload) ? $payload : [];

        $optionKey = $payload['option'] ?? null;
        $optionKey = is_string($optionKey) ? $optionKey : '';

        $raw = $payload['values'] ?? null;
        $subOptions = null;
        if (is_array($raw)) {
            $subOptions = [];
            foreach ($raw as $subKey => $spec) {
                if (!is_string($subKey) || !is_array($spec)) {
                    continue;
                }

                $values = [];
                foreach (is_array($spec['values'] ?? null) ? $spec['values'] : [] as $value) {
                    if (is_string($value)) {
                        $values[] = $value;
                    }
                }

                // Open unless the admin says otherwise: a list nobody vouched for as exhaustive
                // must not close the dropdown on a value the world accepts.
                $subOptions[$subKey] = ['values' => $values, 'closed' => true === ($spec['closed'] ?? false)];
            }
        }

        $result = $this->adminGameLibrary->saveDictOptionValues($gameId, $optionKey, $subOptions);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Jeu introuvable.', 404);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Valeurs invalides.', 422, $result['errors']);
        }

        $game = $result['game'] ?? null;
        if (null === $game) {
            return $this->apiAccessGuard->errorResponse('dict_option_values_save_failed', "L'enregistrement a échoué.", 500);
        }

        return new JsonResponse(['data' => $game, 'meta' => ['message' => null === $subOptions || [] === $subOptions
            ? "Valeurs rendues à l'introspection."
            : 'Valeurs enregistrées.']]);
    }

    #[Route('/api/v1/admin/games/{gameId}/default-yaml', name: 'api_admin_game_save_default_yaml', methods: ['PUT'])]
    public function saveDefaultYaml(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        $defaultYaml = is_array($payload) && is_string($payload['defaultYaml'] ?? null) ? $payload['defaultYaml'] : '';

        $result = $this->adminGameLibrary->saveDefaultYaml($gameId, $defaultYaml);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Jeu introuvable.', 404);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Template invalide.', 422, $result['errors']);
        }

        $game = $result['game'] ?? null;
        if (null === $game) {
            return $this->apiAccessGuard->errorResponse('default_yaml_save_failed', 'L\'enregistrement a échoué.', 500);
        }

        $meta = ['message' => 'Template enregistré.'];
        if (isset($result['warning'])) {
            $meta['warning'] = $result['warning'];
        }

        return new JsonResponse(['data' => $game, 'meta' => $meta]);
    }

    #[Route('/api/v1/admin/games/{gameId}/default-yaml/regenerate', name: 'api_admin_game_regenerate_default_yaml', methods: ['POST'])]
    public function regenerateDefaultYaml(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = $this->adminGameLibrary->regenerateDefaultYaml($gameId);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Jeu introuvable.', 404);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('regenerate_failed', 'La régénération a échoué.', 422, $result['errors']);
        }

        $game = $result['game'] ?? null;
        if (null === $game) {
            return $this->apiAccessGuard->errorResponse('regenerate_failed', 'La régénération a échoué.', 500);
        }

        return new JsonResponse(['data' => $game, 'meta' => ['message' => 'Template régénéré depuis l\'apworld.']]);
    }

    #[Route('/api/v1/admin/games/{gameId}/apworld-preflight', name: 'api_admin_game_apworld_preflight_rerun', methods: ['POST'])]
    public function rerunApworldPreflight(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = $this->adminGameLibrary->rerunApworldPreflight($gameId);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Jeu introuvable.', 404);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('preflight_rerun_failed', 'Le test de génération n\'a pas pu être relancé.', 422, $result['errors']);
        }

        return new JsonResponse(['data' => ['status' => 'pending'], 'meta' => ['message' => 'Test de génération relancé.']], 202);
    }

    #[Route('/api/v1/admin/games/{gameId}/apworld-preflight-override', name: 'api_admin_game_apworld_preflight_override', methods: ['POST'])]
    public function overrideApworldPreflight(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        $overridden = is_array($payload) && true === ($payload['overridden'] ?? null);

        $result = $this->adminGameLibrary->overrideApworldPreflight($gameId, $overridden);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Jeu introuvable.', 404);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('preflight_override_failed', 'La dérogation n\'a pas pu être appliquée.', 422, $result['errors']);
        }

        return new JsonResponse(['data' => ['preflight' => $result['preflight'] ?? null]]);
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
