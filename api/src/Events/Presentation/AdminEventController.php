<?php

declare(strict_types=1);

namespace App\Events\Presentation;

use App\Events\Application\AdminDashboardStats;
use App\Events\Application\AdminEventDrafts;
use App\Events\Application\AdminEventGameSelection;
use App\Events\Application\AdminEventRecap;
use App\Events\Application\PublicEventCatalog;
use App\Events\Application\RegistrationEligibility;
use App\Events\Application\VerifyPrivateEventAccess;
use App\Payments\Application\AdminHelloAssoSyncStatus;
use App\Payments\Application\TriggerHelloAssoSync;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminEventController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminDashboardStats $adminDashboardStats,
        private AdminEventDrafts $adminEventDrafts,
        private AdminEventGameSelection $adminEventGameSelection,
        private AdminEventRecap $adminEventRecap,
        private PublicEventCatalog $publicEventCatalog,
        private RegistrationEligibility $registrationEligibility,
        private VerifyPrivateEventAccess $verifyPrivateEventAccess,
        private TriggerHelloAssoSync $triggerHelloAssoSync,
        private AdminHelloAssoSyncStatus $adminHelloAssoSyncStatus,
    ) {
    }

    #[Route('/api/v1/events', name: 'api_events_public_list', methods: ['GET'])]
    public function publicList(): JsonResponse
    {
        return new JsonResponse(['data' => $this->publicEventCatalog->list(), 'meta' => []]);
    }

    #[Route('/api/v1/events/{eventId}', name: 'api_events_public_show', methods: ['GET'])]
    public function publicShow(string $eventId): JsonResponse
    {
        $event = $this->publicEventCatalog->get($eventId);

        if (null === $event) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Événement introuvable.', 404);
        }

        return new JsonResponse(['data' => $event, 'meta' => []]);
    }

    #[Route('/api/v1/events/{eventId}/registration-eligibility', name: 'api_events_registration_eligibility', methods: ['GET'])]
    public function registrationEligibility(Request $request, string $eventId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->registrationEligibility->check($eventId);

        if (null === $result) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Événement introuvable.', 404);
        }

        return new JsonResponse(['data' => $result, 'meta' => []]);
    }

    #[Route('/api/v1/events/{eventId}/verify-private-access', name: 'api_events_verify_private_access', methods: ['POST'])]
    public function verifyPrivateAccess(Request $request, string $eventId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $payload = $this->jsonPayload($request);
        $result = $this->verifyPrivateEventAccess->verify($eventId, $payload['password'] ?? null, $user->getId());

        if (null === $result) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Événement introuvable.', 404);
        }

        return new JsonResponse(['data' => $result, 'meta' => []]);
    }

    #[Route('/api/v1/admin/dashboard-stats', name: 'api_admin_dashboard_stats', methods: ['GET'])]
    public function dashboardStats(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->adminDashboardStats->getStats(), 'meta' => []]);
    }

    #[Route('/api/v1/admin/events', name: 'api_events_admin_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->adminEventDrafts->list(), 'meta' => []]);
    }

    #[Route('/api/v1/admin/events', name: 'api_events_admin_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = $this->adminEventDrafts->create($this->jsonPayload($request));

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', "Le brouillon d'événement contient des erreurs.", 422, $result['errors']);
        }

        $event = $result['event'] ?? null;
        if (null === $event) {
            return $this->apiAccessGuard->errorResponse('event_creation_failed', 'La création du brouillon a échoué.', 500);
        }

        return new JsonResponse(['data' => $event, 'meta' => ['message' => 'Brouillon créé.']], 201);
    }

    #[Route('/api/v1/admin/events/{eventId}', name: 'api_events_admin_show', methods: ['GET'])]
    public function show(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $event = $this->adminEventDrafts->get($eventId);

        if (null === $event) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Événement introuvable.', 404);
        }

        return new JsonResponse(['data' => $event, 'meta' => []]);
    }

    #[Route('/api/v1/admin/events/{eventId}', name: 'api_events_admin_update', methods: ['PATCH'])]
    public function update(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = $this->adminEventDrafts->update($eventId, $this->jsonPayload($request));

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Événement introuvable.', 404);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', "L'événement contient des erreurs.", 422, $result['errors']);
        }

        $event = $result['event'] ?? null;
        if (null === $event) {
            return $this->apiAccessGuard->errorResponse('event_update_failed', "La mise à jour de l'événement a échoué.", 500);
        }

        return new JsonResponse(['data' => $event, 'meta' => ['message' => 'Événement mis à jour.']]);
    }

    #[Route('/api/v1/admin/events/{eventId}/status', name: 'api_events_admin_transition', methods: ['PATCH'])]
    public function transition(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = $this->jsonPayload($request);
        $result = $this->adminEventDrafts->transition($eventId, $payload['status'] ?? null);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Événement introuvable.', 404);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('invalid_status_transition', 'La transition de statut est invalide.', 422, $result['errors']);
        }

        $event = $result['event'] ?? null;
        if (null === $event) {
            return $this->apiAccessGuard->errorResponse('event_transition_failed', 'La transition de statut a échoué.', 500);
        }

        return new JsonResponse(['data' => $event, 'meta' => ['message' => 'Statut mis à jour.']]);
    }

    #[Route('/api/v1/admin/events/{eventId}/private-access', name: 'api_events_admin_private_access', methods: ['PATCH'])]
    public function configurePrivateAccess(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = $this->jsonPayload($request);
        $result = $this->adminEventDrafts->configurePrivateAccess($eventId, $payload['password'] ?? null);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Événement introuvable.', 404);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('private_access_invalid', "La configuration d'accès privé est invalide.", 422, $result['errors']);
        }

        $event = $result['event'] ?? null;
        if (null === $event) {
            return $this->apiAccessGuard->errorResponse('private_access_failed', "La configuration d'accès privé a échoué.", 500);
        }

        return new JsonResponse(['data' => $event, 'meta' => ['message' => 'Accès privé configuré.']]);
    }

    #[Route('/api/v1/admin/events/{eventId}/game-selection', name: 'api_events_admin_game_selection_get', methods: ['GET'])]
    public function getGameSelection(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $config = $this->adminEventGameSelection->getConfig($eventId);

        if (null === $config) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Événement introuvable.', 404);
        }

        return new JsonResponse(['data' => $config, 'meta' => []]);
    }

    #[Route('/api/v1/admin/events/{eventId}/game-selection', name: 'api_events_admin_game_selection_configure', methods: ['PATCH'])]
    public function configureGameSelection(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = $this->adminEventGameSelection->configure($eventId, $this->jsonPayload($request));

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Événement introuvable.', 404);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'La configuration de sélection de jeux contient des erreurs.', 422, $result['errors']);
        }

        return new JsonResponse(['data' => null, 'meta' => ['message' => 'Sélection de jeux configurée.']], 200);
    }

    #[Route('/api/v1/admin/events/{eventId}/payments/sync', name: 'api_events_admin_payments_sync', methods: ['POST'])]
    public function syncPayments(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = $this->triggerHelloAssoSync->triggerForEvent($eventId);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Événement introuvable.', 404);
        }

        if (!$result['hasFormSlug']) {
            return $this->apiAccessGuard->errorResponse('no_form_configured', 'Aucun formulaire HelloAsso configuré pour cet événement.', 422);
        }

        if (null !== $result['configurationError']) {
            return $this->apiAccessGuard->errorResponse('helloasso_not_configured', $result['configurationError'], 503);
        }

        return new JsonResponse(['data' => null, 'meta' => ['message' => 'Synchronisation déclenchée.']], 202);
    }

    #[Route('/api/v1/admin/events/{eventId}/payments/sync/status', name: 'api_events_admin_payments_sync_status', methods: ['GET'])]
    public function syncStatus(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $status = $this->adminHelloAssoSyncStatus->getForEvent($eventId);

        if (null === $status) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Événement introuvable.', 404);
        }

        return new JsonResponse(['data' => $status, 'meta' => []]);
    }

    #[Route('/api/v1/admin/events/{eventId}/recap', name: 'api_events_admin_recap', methods: ['PATCH'])]
    public function attachRecap(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = $this->adminEventRecap->attach($eventId, $this->jsonPayload($request));

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Événement introuvable.', 404);
        }

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Les données de récap sont invalides.', 422, $result['errors']);
        }

        return new JsonResponse(['data' => null, 'meta' => ['message' => 'Récap attaché.']]);
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
