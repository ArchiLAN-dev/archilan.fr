<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Application\Command\CreatePrivacyRightsRequest;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PrivacyRightsRequestController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private CreatePrivacyRightsRequest $createPrivacyRightsRequest,
    ) {
    }

    #[Route('/api/v1/account/privacy-requests', name: 'api_identity_privacy_rights_request_create', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);

        if ($user instanceof JsonResponse) {
            return $user;
        }

        $payload = $this->jsonPayload($request);
        $result = $this->createPrivacyRightsRequest->create(
            $user,
            is_string($payload['rightType'] ?? null) ? $payload['rightType'] : '',
            is_string($payload['details'] ?? null) ? $payload['details'] : null,
        );

        // Validation failures are thrown as a ValidationException and mapped to HTTP by
        // ApplicationFailureListener (epic 35).
        return new JsonResponse([
            'data' => $result,
            'meta' => [
                'message' => 'Demande RGPD transmise pour traitement manuel.',
                'contactFollowUp' => 'Un membre habilité devra vérifier et traiter la demande hors automatisation.',
            ],
        ], 201);
    }

    /**
     * @return array<mixed>
     */
    private function jsonPayload(Request $request): array
    {
        try {
            $payload = json_decode($request->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($payload) ? $payload : [];
    }
}
