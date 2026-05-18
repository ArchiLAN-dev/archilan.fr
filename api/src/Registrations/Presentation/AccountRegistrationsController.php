<?php

declare(strict_types=1);

namespace App\Registrations\Presentation;

use App\Registrations\Application\AccountRegistrationsQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AccountRegistrationsController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AccountRegistrationsQuery $query,
    ) {
    }

    #[Route('/api/v1/account/registrations', name: 'api_account_registrations', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $registrations = $this->query->findForUser($user->getId());

        return new JsonResponse(['data' => $registrations, 'meta' => []]);
    }
}
