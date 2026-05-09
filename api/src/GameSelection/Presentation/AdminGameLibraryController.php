<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation;

use App\GameSelection\Application\AdminGameLibrary;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminGameLibraryController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminGameLibrary $adminGameLibrary,
    ) {
    }

    #[Route('/api/v1/admin/games', name: 'api_game_selection_admin_games_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->adminGameLibrary->list(), 'meta' => []]);
    }

    #[Route('/api/v1/admin/games', name: 'api_game_selection_admin_games_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

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
        $admin = $this->apiAccessGuard->requireAdmin($request);

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
        $admin = $this->apiAccessGuard->requireAdmin($request);

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

    #[Route('/api/v1/admin/games/{gameId}/apworld', name: 'api_admin_game_configure_apworld', methods: ['PATCH'])]
    public function configureApworld(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $file = $request->files->get('file');

        if (!$file instanceof UploadedFile) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Le fichier est requis.', 422, ['file' => ['Le fichier est requis.']]);
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

    #[Route('/api/v1/admin/games/{gameId}', name: 'api_game_selection_admin_games_delete', methods: ['DELETE'])]
    public function delete(Request $request, string $gameId): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

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
