<?php

declare(strict_types=1);

namespace App\Registrations\Presentation;

use App\Registrations\Application\MyRegistrationQuery;
use App\Registrations\Application\RegistrationCancellation;
use App\Registrations\Application\RegistrationGameSelection;
use App\Registrations\Application\RegistrationSubmission;
use App\Registrations\Application\ReserveRegistration;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class RegistrationController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private MyRegistrationQuery $myRegistrationQuery,
        private RegistrationCancellation $registrationCancellation,
        private RegistrationGameSelection $registrationGameSelection,
        private RegistrationSubmission $registrationSubmission,
        private ReserveRegistration $reserveRegistration,
    ) {
    }

    #[Route('/api/v1/events/{eventId}/my-registration', name: 'api_events_my_registration', methods: ['GET'])]
    public function myRegistration(Request $request, string $eventId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $registration = $this->myRegistrationQuery->findActiveByEventAndUser($eventId, $user->getId());

        if (null === $registration) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Aucune inscription active pour cet événement.', 404);
        }

        return new JsonResponse([
            'data' => ['registrationId' => $registration['registrationId'], 'status' => $registration['status']],
            'meta' => [],
        ]);
    }

    #[Route('/api/v1/events/{eventId}/registrations', name: 'api_events_registrations_reserve', methods: ['POST'])]
    public function reserve(Request $request, string $eventId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->reserveRegistration->reserve($eventId, $user->getId());

        if (null === $result) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Événement introuvable.', 404);
        }

        if ('not_eligible' === $result['outcome']) {
            return $this->apiAccessGuard->errorResponse(
                'not_eligible',
                "L'inscription n'est pas disponible pour cet événement.",
                422,
                ['registration' => [$result['reason']]],
            );
        }

        if ('capacity_full' === $result['outcome']) {
            return $this->apiAccessGuard->errorResponse('capacity_full', 'Cet événement est complet.', 409);
        }

        if ('already_registered' === $result['outcome']) {
            return new JsonResponse([
                'data' => ['outcome' => 'already_registered', 'registrationId' => $result['registrationId']],
                'meta' => [],
            ]);
        }

        return new JsonResponse([
            'data' => ['outcome' => 'reserved', 'registrationId' => $result['registrationId']],
            'meta' => ['message' => 'Place réservée.'],
        ], 201);
    }

    #[Route('/api/v1/registrations/{registrationId}/game-selection', name: 'api_registrations_game_selection_get', methods: ['GET'])]
    public function getGameSelection(Request $request, string $registrationId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->registrationGameSelection->getSelection($registrationId, $user->getId());

        if (null === $result) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Inscription introuvable.', 404);
        }

        return new JsonResponse(['data' => $result, 'meta' => []]);
    }

    #[Route('/api/v1/registrations/{registrationId}/game-selection', name: 'api_registrations_game_selection_put', methods: ['PUT'])]
    public function saveGameSelection(Request $request, string $registrationId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->registrationGameSelection->saveSelection($registrationId, $user->getId(), $this->jsonPayload($request));

        if (null === $result) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Inscription introuvable.', 404);
        }

        if ('error' === $result['outcome']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'La sélection de jeux est invalide.', 422, $result['errors']);
        }

        return new JsonResponse(['data' => ['slots' => $result['slots']], 'meta' => []]);
    }

    #[Route('/api/v1/registrations/{registrationId}/slots/{slotId}/yaml', name: 'api_registrations_slot_yaml_put', methods: ['PUT'])]
    public function saveSlotYaml(Request $request, string $registrationId, string $slotId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $payload = $this->jsonPayload($request);
        $playerYaml = $payload['playerYaml'] ?? null;

        if (!is_string($playerYaml) || '' === trim($playerYaml)) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Le YAML du joueur est requis.', 422, ['playerYaml' => ['Le YAML du joueur est requis.']]);
        }

        $result = $this->registrationGameSelection->saveSlotYaml($registrationId, $user->getId(), $slotId, $playerYaml);

        if (null === $result) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Slot introuvable.', 404);
        }

        if ('error' === $result['outcome']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'La configuration YAML est invalide.', 422, $result['errors']);
        }

        return new JsonResponse(['data' => ['outcome' => 'ok'], 'meta' => []]);
    }

    #[Route('/api/v1/registrations/{registrationId}', name: 'api_registrations_cancel', methods: ['DELETE'])]
    public function cancelRegistration(Request $request, string $registrationId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->registrationCancellation->cancel($registrationId, $user->getId());

        if (null === $result) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Inscription introuvable.', 404);
        }

        if ('error' === $result['outcome']) {
            return $this->apiAccessGuard->errorResponse($result['code'], $result['message'], 422);
        }

        return new JsonResponse(['data' => ['outcome' => 'cancelled'], 'meta' => []]);
    }

    #[Route('/api/v1/registrations/{registrationId}/submit', name: 'api_registrations_submit', methods: ['POST'])]
    public function submitRegistration(Request $request, string $registrationId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->registrationSubmission->submit($registrationId, $user->getId());

        if (null === $result) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Inscription introuvable.', 404);
        }

        if ('error' === $result['outcome']) {
            return $this->apiAccessGuard->errorResponse($result['code'], $result['message'], 422);
        }

        return new JsonResponse([
            'data' => [
                'registrationId' => $result['registrationId'],
                'eventTitle' => $result['eventTitle'],
                'selectedGameIds' => $result['selectedGameIds'],
            ],
            'meta' => ['message' => 'Inscription confirmée. À très bientôt !'],
        ]);
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
