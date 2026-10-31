<?php

declare(strict_types=1);

namespace App\Registrations\Presentation\Controller;

use App\Registrations\Application\Command\ChooseBroadcastConsent;
use App\Registrations\Application\Query\BroadcastConsentQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Story 47.1: the member's consent to the ArchiLAN broadcast showing their stream, on their own registration. */
final readonly class BroadcastConsentController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private BroadcastConsentQuery $query,
        private ChooseBroadcastConsent $choose,
    ) {
    }

    #[Route('/api/v1/registrations/{registrationId}/broadcast-consent', name: 'api_registrations_broadcast_consent_get', methods: ['GET'])]
    public function show(Request $request, string $registrationId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $consent = $this->query->forOwner($registrationId, $user->getId());
        if (null === $consent) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Inscription introuvable.', 404);
        }

        return new JsonResponse(['data' => $consent, 'meta' => []]);
    }

    #[Route('/api/v1/registrations/{registrationId}/broadcast-consent', name: 'api_registrations_broadcast_consent_put', methods: ['PUT'])]
    public function choose(Request $request, string $registrationId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $payload = json_decode($request->getContent(), true);
        $consent = is_array($payload) ? ($payload['consent'] ?? null) : null;
        if (!is_bool($consent)) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
        }

        // Failures (missing or not the caller's, seat cancelled, event over) are typed ApplicationFailures (epic 35).
        $this->choose->choose($registrationId, $user->getId(), $consent);

        return new JsonResponse(['data' => ['consent' => $consent], 'meta' => []]);
    }
}
