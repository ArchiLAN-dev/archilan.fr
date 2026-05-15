<?php

declare(strict_types=1);

namespace App\Registrations\Presentation;

use App\Registrations\Application\AdminRegistrationCancellation;
use App\Registrations\Application\AdminRegistrationDashboard;
use App\Registrations\Application\AdminRegistrationExporter;
use App\Registrations\Application\AdminRegistrationInspector;
use App\Registrations\Application\AdminRegistrationModification;
use App\Registrations\Application\SendMessageToRegistrant;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
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
        private AdminRegistrationDashboard $adminRegistrationDashboard,
        private AdminRegistrationExporter $adminRegistrationExporter,
        private AdminRegistrationInspector $adminRegistrationInspector,
        private AdminRegistrationModification $adminRegistrationModification,
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

        $result = $this->adminRegistrationModification->update($eventId, $registrationId, $input);
        $occurredAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $this->logger->info('admin.registrations.update', [
            'eventId' => $eventId,
            'registrationId' => $registrationId,
            'adminId' => $user->getId(),
            'outcome' => $result['outcome'],
            'occurredAt' => $occurredAt,
        ]);

        if ('not_found' === $result['outcome']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Inscription introuvable.', 404);
        }

        if ('inactive' === $result['outcome']) {
            return $this->apiAccessGuard->errorResponse('inactive_registration', 'L\'inscription n\'est plus modifiable.', 409);
        }

        if ('error' === $result['outcome']) {
            return $this->apiAccessGuard->errorResponse('invalid_registration_update', 'La modification contient des erreurs.', 422, $result['errors']);
        }

        $detail = $this->adminRegistrationInspector->inspect($eventId, $registrationId);

        return new JsonResponse(['data' => $detail, 'meta' => ['outcome' => 'updated']]);
    }

    #[Route('/api/v1/admin/events/{eventId}/registrations/{registrationId}', name: 'api_admin_registrations_cancel', methods: ['DELETE'])]
    public function cancel(Request $request, string $eventId, string $registrationId): JsonResponse
    {
        $user = $this->requireAuthenticatedAdmin($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->adminRegistrationCancellation->cancel($eventId, $registrationId);
        $occurredAt = (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM);

        $this->logger->info('admin.registrations.cancel', [
            'eventId' => $eventId,
            'registrationId' => $registrationId,
            'adminId' => $user->getId(),
            'outcome' => $result['outcome'],
            'occurredAt' => $occurredAt,
        ]);

        if ('not_found' === $result['outcome']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Inscription introuvable.', 404);
        }

        if ('already_cancelled' === $result['outcome']) {
            return $this->apiAccessGuard->errorResponse('already_cancelled', 'L\'inscription est déjà annulée.', 409);
        }

        return new JsonResponse(['outcome' => 'cancelled']);
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

        $result = $this->sendMessageToRegistrant->send($eventId, $registrationId, $user->getId(), $subject, $body);

        if ('not_found' === $result['outcome']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Inscription introuvable.', 404);
        }

        if ('send_failed' === $result['outcome']) {
            return $this->apiAccessGuard->errorResponse('message_send_failed', 'L\'envoi du message a échoué.', 502);
        }

        $this->logger->info('admin.registrations.message_sent', [
            'eventId' => $eventId,
            'registrationId' => $registrationId,
            'adminId' => $user->getId(),
            'subject' => $subject,
            'sentAt' => $result['sentAt'],
        ]);

        return new JsonResponse(['data' => ['outcome' => $result['outcome'], 'sentAt' => $result['sentAt']]]);
    }
}
