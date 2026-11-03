<?php

declare(strict_types=1);

namespace App\Wallet\Presentation\Controller;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use App\Wallet\Application\Command\ClaimMinimapperzCode;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 49.2: a registrant's Minimapperz code. POST because the first call hands a code out; later calls give the
 * same one. `data` is null when there is no code to show.
 */
final readonly class MinimapperzCodeController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private ClaimMinimapperzCode $claim,
    ) {
    }

    #[Route('/api/v1/events/{eventId}/minimapperz/code', name: 'api_wallet_minimapperz_code', methods: ['POST'])]
    public function claim(Request $request, string $eventId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $claim = $this->claim->claim($eventId, $user->getId());

        return new JsonResponse(['data' => null === $claim ? null : ['code' => $claim->code, 'lanUrl' => $claim->lanUrl]]);
    }
}
