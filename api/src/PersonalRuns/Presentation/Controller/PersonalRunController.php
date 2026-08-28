<?php

declare(strict_types=1);

namespace App\PersonalRuns\Presentation\Controller;

use App\PersonalRuns\Application\Command\PersonalRunGameConfig;
use App\PersonalRuns\Application\Command\PersonalRunLifecycle;
use App\PersonalRuns\Application\Service\PersonalRunDrafts;
use App\PersonalRuns\Application\Service\PersonalRunGameSelection;
use App\PersonalRuns\Application\Service\PersonalRunSeedImport;
use App\PersonalRuns\Application\Service\RunSlotCoPlayers;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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
        private RunSlotCoPlayers $coPlayers,
        private PersonalRunSeedImport $seedImport,
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
            return $this->apiAccessGuard->errorResponse('run_creation_failed', 'La création de la run a échoué.', 500);
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

        // ROLE_ADMIN here is a display/role gate (not a membership gate), allowed per
        // api/CLAUDE.md AC-M3: an admin may read any private run, as they already may retrieve its
        // spoiler and stop it from the member's admin sheet.
        $isAdmin = in_array('ROLE_ADMIN', $user->getRoles(), true);

        $result = $this->drafts->get($runId, $user->getId(), $isAdmin);

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

        if ($result['blocked']) {
            return $this->apiAccessGuard->errorResponse('run_finished', 'Cette partie est terminée : le lien d\'invitation ne peut plus être régénéré.', 409);
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

        // Failures (missing, not owner, wrong state) are thrown as typed ApplicationFailures and mapped
        // to HTTP by ApplicationFailureListener (epic 35).
        $result = $this->lifecycle->start($runId, $user->getId());

        return new JsonResponse(['data' => ['runId' => $result->runId, 'status' => $result->status]], 202);
    }

    #[Route('/api/v1/runs/{runId}/stop', name: 'api_runs_stop', methods: ['POST'])]
    public function stop(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->lifecycle->stop($runId, $user->getId());

        return new JsonResponse(['data' => ['runId' => $result->runId, 'status' => $result->status]], 202);
    }

    #[Route('/api/v1/runs/{runId}/finish', name: 'api_runs_finish', methods: ['POST'])]
    public function finish(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->lifecycle->finish($runId, $user->getId());

        return new JsonResponse(['data' => ['runId' => $result->runId, 'status' => $result->status]]);
    }

    #[Route('/api/v1/runs/{runId}/title', name: 'api_runs_rename', methods: ['PUT'])]
    public function rename(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->drafts->rename($runId, $user->getId(), $this->jsonPayload($request));

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Run introuvable.', 404);
        }

        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Données invalides.', 422, $result['errors']);
        }

        return new JsonResponse(['data' => $result['run']]);
    }

    #[Route('/api/v1/runs/{runId}/recap-visibility', name: 'api_runs_recap_visibility', methods: ['PUT'])]
    public function setRecapVisibility(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $payload = $this->jsonPayload($request);
        $public = $payload['public'] ?? null;
        if (!is_bool($public)) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Champ "public" (booléen) requis.', 422);
        }

        // Missing run / not owner are thrown as typed failures and mapped to HTTP by the epic-35 listener.
        $result = $this->lifecycle->setRecapVisibility($runId, $user->getId(), $public);

        return new JsonResponse(['data' => ['runId' => $result->runId, 'recapPublic' => $result->recapPublic]]);
    }

    #[Route('/api/v1/runs/{runId}/games', name: 'api_runs_configure_games', methods: ['PATCH'])]
    public function configureGames(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $this->gameConfig->configure($runId, $user->getId(), $this->jsonPayload($request));

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

    #[Route('/api/v1/runs/{runId}/participants/me/slots/{slotId}/preflight', name: 'api_runs_slot_preflight_request', methods: ['POST'])]
    public function requestSlotPreflight(Request $request, string $runId, string $slotId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->gameSelection->requestSlotPreflight($runId, $user->getId(), $slotId);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Run introuvable.', 404);
        }

        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Accès refusé.', 403);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Test impossible.', 422, $result['errors']);
        }

        return new JsonResponse(['data' => ['status' => 'pending']], 202);
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

    /**
     * Replace the whole co-player roster of a slot (story 16.17). A full list rather than an
     * add/remove pair: the same call adds, removes and reorders, and repeating it changes nothing.
     */
    #[Route('/api/v1/runs/{runId}/slots/{slotId}/co-players', name: 'api_runs_slot_co_players_replace', methods: ['PUT'])]
    public function replaceSlotCoPlayers(Request $request, string $runId, string $slotId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $payload = $this->jsonPayload($request);
        $raw = is_array($payload['userIds'] ?? null) ? $payload['userIds'] : [];
        $userIds = array_values(array_filter($raw, is_string(...)));

        $result = $this->coPlayers->replace($runId, $user->getId(), $slotId, $userIds);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Run introuvable.', 404);
        }

        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Seul le propriétaire de la partie gère les co-joueurs.', 403);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Co-joueurs invalides.', 422, $result['errors']);
        }

        return new JsonResponse(['data' => ['coPlayers' => $result['coPlayers']]]);
    }

    /**
     * Import a seed generated somewhere else (story 16.18). The archive becomes the party: no yamls
     * are collected and no generation runs, at the price of the detailed progression.
     */
    #[Route('/api/v1/runs/{runId}/seed', name: 'api_runs_seed_import', methods: ['POST'])]
    public function importSeed(Request $request, string $runId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile || !$file->isValid()) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Fichier manquant ou invalide.', 422, ['file' => ['Fichier manquant ou invalide.']]);
        }

        $result = $this->seedImport->import(
            $runId,
            $user->getId(),
            (string) file_get_contents($file->getPathname()),
            $file->getClientOriginalName(),
            in_array('ROLE_ADMIN', $user->getRoles(), true),
        );

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Run introuvable.', 404);
        }

        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Seul le propriétaire de la partie peut importer une seed.', 403);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Seed invalide.', 422, $result['errors']);
        }

        return new JsonResponse(['data' => ['slots' => $result['slots']]]);
    }

    /** Assign a slot of the imported archive to zero or more participants (story 16.18). */
    #[Route('/api/v1/runs/{runId}/imported-slots/{slotId}', name: 'api_runs_imported_slot_assign', methods: ['PUT'])]
    public function assignImportedSlot(Request $request, string $runId, string $slotId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $payload = $this->jsonPayload($request);
        $raw = is_array($payload['userIds'] ?? null) ? $payload['userIds'] : [];
        $userIds = array_values(array_filter($raw, is_string(...)));

        $result = $this->seedImport->assign($runId, $user->getId(), $slotId, $userIds, in_array('ROLE_ADMIN', $user->getRoles(), true));

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Run introuvable.', 404);
        }

        if (!$result['authorized']) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Seul le propriétaire de la partie assigne les slots.', 403);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Assignation impossible.', 422, $result['errors']);
        }

        return new JsonResponse(['data' => ['slots' => $result['slots']]]);
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

        $result = $this->drafts->hardDelete($runId, $user->getId(), in_array('ROLE_ADMIN', $user->getRoles(), true));

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
