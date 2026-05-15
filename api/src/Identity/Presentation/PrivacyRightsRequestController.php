<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Application\CreatePrivacyRightsRequest;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
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

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'La demande RGPD contient des erreurs.', 422, $result['errors']);
        }

        $createdRequest = $result['request'] ?? null;
        if (null === $createdRequest) {
            return $this->apiAccessGuard->errorResponse('privacy_request_failed', 'La demande RGPD a échoué.', 500);
        }

        return new JsonResponse([
            'data' => $createdRequest,
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
