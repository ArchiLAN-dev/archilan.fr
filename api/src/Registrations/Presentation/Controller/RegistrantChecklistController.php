<?php

declare(strict_types=1);

namespace App\Registrations\Presentation\Controller;

use App\Registrations\Application\Query\RegistrantChecklistQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/** Story 53.2: what the registrant still has to do, for their hub. */
final readonly class RegistrantChecklistController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private RegistrantChecklistQuery $checklistQuery,
    ) {
    }

    #[Route('/api/v1/registrations/{registrationId}/checklist', name: 'api_registrations_checklist', methods: ['GET'])]
    public function __invoke(Request $request, string $registrationId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse([
            'data' => $this->checklistQuery->forOwner($registrationId, $user->getId()),
            'meta' => [],
        ]);
    }
}
