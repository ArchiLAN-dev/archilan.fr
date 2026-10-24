<?php

declare(strict_types=1);

namespace App\Wallet\Presentation\Controller;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use App\Wallet\Application\Command\CreditBlindTestSource;
use App\Wallet\Application\Command\IssueBlindTestJoinLink;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The blind test of an event (story 41.40): a member's signed way into the app, and the app's credits for the
 * sources found. The credit route carries no member session - its signature is its authentication.
 */
final readonly class BlindTestController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private IssueBlindTestJoinLink $joinLink,
        private CreditBlindTestSource $credit,
    ) {
    }

    #[Route('/api/v1/events/{eventId}/blind-test/join', name: 'api_wallet_blind_test_join', methods: ['GET'])]
    public function join(Request $request, string $eventId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => ['url' => $this->joinLink->issue($user->getId(), $eventId)->url]]);
    }

    #[Route('/api/v1/integrations/blind-test/credits', name: 'api_wallet_blind_test_credit', methods: ['POST'])]
    public function credit(Request $request): JsonResponse
    {
        $result = $this->credit->credit(
            $request->headers->get('X-Archilan-Timestamp', ''),
            $request->headers->get('X-Archilan-Signature', ''),
            $request->getContent(),
        );

        return new JsonResponse(
            ['data' => ['credited' => $result->credited, 'balance' => $result->balance]],
            $result->duplicate ? 200 : 201,
        );
    }
}
