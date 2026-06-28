<?php

declare(strict_types=1);

namespace App\PersonalRuns\Presentation;

use App\PersonalRuns\Application\PersonalRunDrafts;
use App\PersonalRuns\Application\PersonalRunGameConfig;
use App\PersonalRuns\Application\PersonalRunGameSelection;
use App\PersonalRuns\Application\PersonalRunLifecycle;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PersonalRunController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private PersonalRunDrafts $drafts,
        private PersonalRunGameConfig $gameConfig,
        private PersonalRunGameSelection $gameSelection,
        private PersonalRunLifecycle $lifecycle,
    ) {
    }

    #[Route('/api/v1/runs', name: 'api_runs_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->drafts->create($user->getId(), $this->jsonPayload($request));

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Données invalides.', 422, $result['errors']);
        }

        $run = $result['run'];
        if (null === $run) {
            return $this->apiAccessGuard->errorResponse('run_creation_failed', 'La création du run a échoué.', 500);
        }

        return new JsonResponse(['data' => $run], 201);
    }

    #[Route('/api/v1/runs/mine', name: 'api_runs_list_mine', methods: ['GET'])]
    public function listMine(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => $this->drafts->listMine($user->getId())]);
    }

    #[Route('/api/v1/runs/invite/{inviteToken}/preview', name: 'api_runs_invite_preview', methods: ['GET'])]
    public function invitePreview(string $inviteToken): JsonResponse
    {
        $preview = $this->drafts->previewByToken($inviteToken);

        if (null === $preview) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Lien d\'invitation introuvable.', 404);
        }

        return new JsonResponse(['data' => $preview]);
    }

    #[Route('/api/v1/runs/join/{inviteToken}', name: 'api_runs_join', methods: ['GET'])]
    public function join(Request $request, string $inviteToken): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $this->apiAccessGuard->errorResponse('auth_required', 'Authentification requise.', 401);
        }

        $result = $this->drafts->joinByToken($inviteToken, $user->getId());

        if ('not_found' === $result['status']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Lien d\'invitation introuvable.', 404);
        }

        return new JsonResponse(['data' => $result['payload']]);
    }

    #[Route('/api/v1/runs/{runId}', name: 'api_runs_get', methods: ['GET'])]
    public function get(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->drafts->get($runId, $user->getId());

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Run introuvable.', 404);
        }

        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        return new JsonResponse(['data' => $result['payload']]);
    }

    #[Route('/api/v1/runs/{runId}/invite/regenerate', name: 'api_runs_invite_regenerate', methods: ['POST'])]
    public function regenerateInvite(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->drafts->regenerateToken($runId, $user->getId());

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Run introuvable.', 404);
        }

        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        return new JsonResponse(['data' => [
            'inviteToken' => $result['inviteToken'],
            'inviteUrl' => $result['inviteUrl'],
        ]]);
    }

    #[Route('/api/v1/runs/{runId}/start', name: 'api_runs_start', methods: ['POST'])]
    public function start(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->lifecycle->start($runId, $user->getId());

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Run introuvable.', 404);
        }

        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        if ($result['blocked']) {
            $code = $result['blockReason'] ?? 'run_already_active';

            return $this->apiAccessGuard->errorResponse($code, 'Démarrage impossible dans l\'état actuel.', 422);
        }

        return new JsonResponse(['data' => ['runId' => $result['runId'], 'status' => $result['status']]], 202);
    }

    #[Route('/api/v1/runs/{runId}/stop', name: 'api_runs_stop', methods: ['POST'])]
    public function stop(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->lifecycle->stop($runId, $user->getId());

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Run introuvable.', 404);
        }

        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        if ($result['blocked']) {
            $code = $result['blockReason'] ?? 'run_not_active';

            return $this->apiAccessGuard->errorResponse($code, 'Arrêt impossible dans l\'état actuel.', 422);
        }

        return new JsonResponse(['data' => ['runId' => $result['runId'], 'status' => $result['status']]], 202);
    }

    #[Route('/api/v1/runs/{runId}/finish', name: 'api_runs_finish', methods: ['POST'])]
    public function finish(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->lifecycle->finish($runId, $user->getId());

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Run introuvable.', 404);
        }

        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        if ($result['blocked']) {
            $code = $result['blockReason'] ?? 'run_not_active';

            return $this->apiAccessGuard->errorResponse($code, 'Impossible de terminer la run dans son état actuel.', 409);
        }

        return new JsonResponse(['data' => ['runId' => $result['runId'], 'status' => $result['status']]]);
    }

    #[Route('/api/v1/runs/{runId}/games', name: 'api_runs_configure_games', methods: ['PATCH'])]
    public function configureGames(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->gameConfig->configure($runId, $user->getId(), $this->jsonPayload($request));

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Run introuvable.', 404);
        }

        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        if ($result['blocked']) {
            $code = $result['blockReason'] ?? 'run_active';

            return $this->apiAccessGuard->errorResponse($code, 'Modification impossible dans l\'état actuel.', 422);
        }

        if ([] !== $result['errors']) {
            $code = $result['errorCode'] ?? 'validation_failed';

            return $this->apiAccessGuard->errorResponse($code, 'Configuration de jeux invalide.', 422, $result['errors']);
        }

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/runs/{runId}/participants/me/game-selection', name: 'api_runs_game_selection_get', methods: ['GET'])]
    public function getMyGameSelection(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->gameSelection->getMySlots($runId, $user->getId());

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Run introuvable.', 404);
        }

        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        return new JsonResponse(['data' => [
            'status' => $result['status'],
            'slots' => $result['slots'],
            'availableGames' => $result['availableGames'],
            'recentlyPlayedGames' => $result['recentlyPlayedGames'],
        ]]);
    }

    #[Route('/api/v1/runs/{runId}/participants/{participantId}/game-selection', name: 'api_runs_participant_game_selection_get', methods: ['GET'])]
    public function getParticipantGameSelection(Request $request, string $runId, string $participantId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->gameSelection->getParticipantSlots($runId, $user->getId(), $participantId);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Participant introuvable.', 404);
        }

        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        return new JsonResponse(['data' => [
            'participant' => $result['participant'],
            'slots' => $result['slots'],
        ]]);
    }

    #[Route('/api/v1/runs/{runId}/participants/me/games', name: 'api_runs_game_selection_save', methods: ['PUT'])]
    public function saveMyGames(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->gameSelection->saveMyGames($runId, $user->getId(), $this->jsonPayload($request));

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Run introuvable.', 404);
        }

        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        if ($result['blocked']) {
            $code = $result['blockReason'] ?? 'run_active';

            return $this->apiAccessGuard->errorResponse($code, 'Modification impossible dans l\'état actuel.', 422);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Sélection invalide.', 422, $result['errors']);
        }

        return new JsonResponse(['data' => ['slots' => $result['slots']]]);
    }

    #[Route('/api/v1/runs/{runId}/participants/me/slots/{slotId}/yaml', name: 'api_runs_slot_yaml_save', methods: ['PUT'])]
    public function saveSlotYaml(Request $request, string $runId, string $slotId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $payload = $this->jsonPayload($request);
        $playerYaml = is_string($payload['playerYaml'] ?? null) ? $payload['playerYaml'] : '';

        if ('' === $playerYaml) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Le YAML est requis.', 422, ['playerYaml' => ['Le YAML est requis.']]);
        }

        $result = $this->gameSelection->saveSlotYaml($runId, $user->getId(), $slotId, $playerYaml);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Run introuvable.', 404);
        }

        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        if ($result['blocked']) {
            $code = $result['blockReason'] ?? 'run_active';

            return $this->apiAccessGuard->errorResponse($code, 'Modification impossible dans l\'état actuel.', 422);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'YAML invalide.', 422, $result['errors']);
        }

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/runs/{runId}/unarchive', name: 'api_runs_unarchive', methods: ['POST'])]
    public function unarchive(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->drafts->unarchive($runId, $user->getId());

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Run introuvable.', 404);
        }

        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        if ($result['blocked']) {
            return $this->apiAccessGuard->errorResponse($result['blockReason'] ?? 'run_not_archived', 'Désarchivage impossible.', 422);
        }

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/runs/{runId}/archive', name: 'api_runs_archive', methods: ['POST'])]
    public function archive(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->drafts->archive($runId, $user->getId());

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Run introuvable.', 404);
        }

        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        if ($result['blocked']) {
            return $this->apiAccessGuard->errorResponse($result['blockReason'] ?? 'run_not_archivable', 'Archivage impossible dans l\'état actuel.', 422);
        }

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/runs/{runId}', name: 'api_runs_delete', methods: ['DELETE'])]
    public function delete(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->drafts->hardDelete($runId, $user->getId());

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Run introuvable.', 404);
        }

        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        if ($result['blocked']) {
            return $this->apiAccessGuard->errorResponse($result['blockReason'] ?? 'run_active', 'Suppression impossible dans l\'état actuel.', 422);
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
