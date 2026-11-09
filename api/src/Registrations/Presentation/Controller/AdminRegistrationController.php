<?php

declare(strict_types=1);

namespace App\Registrations\Presentation\Controller;

use App\Registrations\Application\Command\AdminRegistrationCancellation;
use App\Registrations\Application\Command\AdminRegistrationConfirmation;
use App\Registrations\Application\Command\AdminRegistrationCreation;
use App\Registrations\Application\Command\AdminRegistrationModification;
use App\Registrations\Application\Command\AdminSlotYamlModification;
use App\Registrations\Application\Command\SendMessageToRegistrant;
use App\Registrations\Application\Query\AdminRegistrationDashboard;
use App\Registrations\Application\Query\AdminRegistrationExporter;
use App\Registrations\Application\Query\AdminRegistrationInspector;
use App\Registrations\Application\Service\RegistrationGameSelection;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminRegistrationController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminRegistrationCancellation $adminRegistrationCancellation,
        private AdminRegistrationConfirmation $adminRegistrationConfirmation,
        private AdminRegistrationCreation $adminRegistrationCreation,
        private AdminRegistrationDashboard $adminRegistrationDashboard,
        private AdminRegistrationExporter $adminRegistrationExporter,
        private AdminRegistrationInspector $adminRegistrationInspector,
        private AdminRegistrationModification $adminRegistrationModification,
        private AdminSlotYamlModification $adminSlotYamlModification,
        private RegistrationGameSelection $registrationGameSelection,
        private SendMessageToRegistrant $sendMessageToRegistrant,
        private LoggerInterface $logger,
    ) {
    }

    #[Route('/api/v1/admin/events/{eventId}/registrations', name: 'api_admin_registrations_list', methods: ['GET'])]
    public function list(Request $request, string $eventId): JsonResponse
    {
        $user = $this->requireAuthenticatedAdmin($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $statusFilter = $request->query->getString('status') ?: null;
        $registrations = $this->adminRegistrationDashboard->list($eventId, $statusFilter);

        if (null === $registrations) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Événement introuvable.', 404);
        }

        return new JsonResponse(['data' => $registrations, 'meta' => ['total' => count($registrations)]]);
    }

    #[Route('/api/v1/admin/events/{eventId}/registrations/export', name: 'api_admin_registrations_export', methods: ['GET'])]
    public function export(Request $request, string $eventId): JsonResponse
    {
        $user = $this->requireAuthenticatedAdmin($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $includeCancelled = $request->query->getBoolean('include_cancelled');
        $payload = $this->adminRegistrationExporter->export($eventId, $includeCancelled);

        if (null === $payload) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Événement introuvable.', 404);
        }

        $this->logger->info('admin.registrations.export', [
            'eventId' => $eventId,
            'adminId' => $user->getId(),
            'includeCancelled' => $includeCancelled,
        ]);

        $response = new JsonResponse($payload);
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="registrations-%s.json"', $eventId),
        );

        return $response;
    }

    #[Route('/api/v1/admin/events/{eventId}/registrations/{registrationId}', name: 'api_admin_registrations_show', methods: ['GET'])]
    public function show(Request $request, string $eventId, string $registrationId): JsonResponse
    {
        $user = $this->requireAuthenticatedAdmin($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $detail = $this->adminRegistrationInspector->inspect($eventId, $registrationId);

        if (null === $detail) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Inscription introuvable.', 404);
        }

        return new JsonResponse(['data' => $detail]);
    }

    #[Route('/api/v1/admin/events/{eventId}/registrations/{registrationId}', name: 'api_admin_registrations_update', methods: ['PATCH'])]
    public function update(Request $request, string $eventId, string $registrationId): JsonResponse
    {
        $user = $this->requireAuthenticatedAdmin($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Payload JSON invalide.', 400);
        }
        $input = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $input[$key] = $value;
            }
        }

        // Failures (missing, inactive, invalid) are thrown as typed ApplicationFailures and mapped to HTTP
        // by ApplicationFailureListener (epic 35). The per-outcome audit log now lives in the command.
        $this->adminRegistrationModification->update($eventId, $registrationId, $user->getId(), $input);

        $detail = $this->adminRegistrationInspector->inspect($eventId, $registrationId);

        return new JsonResponse(['data' => $detail, 'meta' => ['outcome' => 'updated']]);
    }

    /** Story 5.9: what the YAML editor needs (games, options, slots) when an admin edits the registration. */
    #[Route('/api/v1/admin/events/{eventId}/registrations/{registrationId}/game-selection', name: 'api_admin_registrations_game_selection', methods: ['GET'])]
    public function gameSelection(Request $request, string $eventId, string $registrationId): JsonResponse
    {
        $user = $this->requireAuthenticatedAdmin($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->registrationGameSelection->getSelectionForAdmin($eventId, $registrationId);

        if (null === $result) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Inscription introuvable.', 404);
        }

        return new JsonResponse(['data' => $result, 'meta' => []]);
    }

    /** Story 5.9: an admin saves a slot's YAML on the player's behalf, whatever the registration window. */
    #[Route('/api/v1/admin/events/{eventId}/registrations/{registrationId}/slots/{slotId}/yaml', name: 'api_admin_registrations_slot_yaml', methods: ['PUT'])]
    public function saveSlotYaml(Request $request, string $eventId, string $registrationId, string $slotId): JsonResponse
    {
        $user = $this->requireAuthenticatedAdmin($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        $playerYaml = is_array($payload) ? ($payload['playerYaml'] ?? null) : null;
        if (!is_string($playerYaml) || '' === trim($playerYaml)) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Le YAML du joueur est requis.', 422, ['playerYaml' => ['Le YAML du joueur est requis.']]);
        }

        // Failures (missing, cancelled, invalid) are typed ApplicationFailures mapped by ApplicationFailureListener.
        $this->adminSlotYamlModification->save($eventId, $registrationId, $slotId, $user->getId(), $playerYaml);

        return new JsonResponse(['data' => ['outcome' => 'ok'], 'meta' => []]);
    }

    #[Route('/api/v1/admin/events/{eventId}/registrations/{registrationId}', name: 'api_admin_registrations_cancel', methods: ['DELETE'])]
    public function cancel(Request $request, string $eventId, string $registrationId): JsonResponse
    {
        $user = $this->requireAuthenticatedAdmin($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Failures (missing, already cancelled) are thrown as typed ApplicationFailures and mapped to HTTP
        // by ApplicationFailureListener (epic 35). The per-outcome audit log now lives in the command.
        $this->adminRegistrationCancellation->cancel($eventId, $registrationId, $user->getId());

        return new JsonResponse(['outcome' => 'cancelled']);
    }

    /**
     * Story 5.10: the admin registers a member on their behalf. `{userId, acknowledgeWarnings?}`. Member-side rules
     * come back as a 409 `warnings_to_acknowledge` until acknowledged.
     */
    #[Route('/api/v1/admin/events/{eventId}/registrations', name: 'api_admin_registrations_create', methods: ['POST'])]
    public function create(Request $request, string $eventId): JsonResponse
    {
        $user = $this->requireAuthenticatedAdmin($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload) || !isset($payload['userId']) || !is_string($payload['userId']) || '' === $payload['userId']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Choisis un joueur.', 422, ['userId' => ['Choisis un joueur.']]);
        }

        $result = $this->adminRegistrationCreation->create($eventId, $payload['userId'], $user->getId(), $this->acknowledged($payload));

        return new JsonResponse(
            ['data' => ['registrationId' => $result->registrationId, 'outcome' => $result->outcome->value]],
            'created' === $result->outcome->value ? 201 : 200,
        );
    }

    /** Story 5.10: a cancelled registration comes back with its games and YAML. `{acknowledgeWarnings?}`. */
    #[Route('/api/v1/admin/events/{eventId}/registrations/{registrationId}/reactivate', name: 'api_admin_registrations_reactivate', methods: ['POST'])]
    public function reactivate(Request $request, string $eventId, string $registrationId): JsonResponse
    {
        $user = $this->requireAuthenticatedAdmin($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        $this->adminRegistrationCreation->reactivate($eventId, $registrationId, $user->getId(), is_array($payload) && $this->acknowledged($payload));

        return new JsonResponse(['outcome' => 'reactivated']);
    }

    /** Story 5.10: the admin confirms on the member's behalf. `{sendEmail?, acknowledgeWarnings?}`; the e-mail goes by default. */
    #[Route('/api/v1/admin/events/{eventId}/registrations/{registrationId}/confirm', name: 'api_admin_registrations_confirm', methods: ['POST'])]
    public function confirm(Request $request, string $eventId, string $registrationId): JsonResponse
    {
        $user = $this->requireAuthenticatedAdmin($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        $payload = is_array($payload) ? $payload : [];
        $this->adminRegistrationConfirmation->confirm(
            $eventId,
            $registrationId,
            $user->getId(),
            false !== ($payload['sendEmail'] ?? true),
            $this->acknowledged($payload),
        );

        return new JsonResponse(['outcome' => 'confirmed']);
    }

    #[Route('/api/v1/admin/events/{eventId}/registrations/{registrationId}/messages', name: 'api_admin_registrations_message', methods: ['POST'])]
    public function message(Request $request, string $eventId, string $registrationId): JsonResponse
    {
        $user = $this->requireAuthenticatedAdmin($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $data = $request->toArray();
        $subjectRaw = $data['subject'] ?? '';
        $bodyRaw = $data['body'] ?? '';
        $subject = trim(is_string($subjectRaw) ? $subjectRaw : '');
        $body = trim(is_string($bodyRaw) ? $bodyRaw : '');

        if ('' === $subject || '' === $body) {
            return $this->apiAccessGuard->errorResponse('validation_error', 'Le sujet et le corps du message sont requis.', 422);
        }

        // Failures (registration missing, mailer refused) are thrown as typed ApplicationFailures and
        // mapped to HTTP by ApplicationFailureListener (epic 35).
        $sentAt = $this->sendMessageToRegistrant->send($eventId, $registrationId, $user->getId(), $subject, $body);

        $this->logger->info('admin.registrations.message_sent', [
            'eventId' => $eventId,
            'registrationId' => $registrationId,
            'adminId' => $user->getId(),
            'subject' => $subject,
            'sentAt' => $sentAt,
        ]);

        return new JsonResponse(['data' => ['outcome' => 'sent', 'sentAt' => $sentAt]]);
    }

    /**
     * @param array<mixed> $payload
     */
    private function acknowledged(array $payload): bool
    {
        return true === ($payload['acknowledgeWarnings'] ?? false);
    }
}
