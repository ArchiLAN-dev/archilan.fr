<?php

declare(strict_types=1);

namespace App\Wallet\Presentation\Controller;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use App\Wallet\Application\Command\CreditBlindTestSource;
use App\Wallet\Application\Command\ExchangeBlindTestLoginCode;
use App\Wallet\Application\Command\IssueBlindTestLoginCode;
use App\Wallet\Application\Query\BlindTestEntry;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The blind test of an event (stories 41.40, 41.45): where a member enters the app, the one-time code that tells the
 * app who they are, and the app's credits for the sources found. The two `integrations` routes carry no member
 * session - their signature is their authentication.
 */
final readonly class BlindTestController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private BlindTestEntry $entry,
        private IssueBlindTestLoginCode $issueCode,
        private ExchangeBlindTestLoginCode $exchangeCode,
        private CreditBlindTestSource $credit,
    ) {
    }

    #[Route('/api/v1/events/{eventId}/blind-test/access', name: 'api_wallet_blind_test_access', methods: ['GET'])]
    public function access(Request $request, string $eventId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => ['url' => $this->entry->entryUrl($user->getId(), $eventId)]]);
    }

    #[Route('/api/v1/events/{eventId}/blind-test/codes', name: 'api_wallet_blind_test_code', methods: ['POST'])]
    public function code(Request $request, string $eventId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $payload = json_decode($request->getContent(), true);
        $state = is_array($payload) && is_string($payload['state'] ?? null) ? $payload['state'] : '';

        return new JsonResponse(['data' => ['redirect' => $this->issueCode->issue($user->getId(), $eventId, $state)->url]]);
    }

    #[Route('/api/v1/integrations/blind-test/codes/echange', name: 'api_wallet_blind_test_code_exchange', methods: ['POST'])]
    public function exchange(Request $request): JsonResponse
    {
        $identity = $this->exchangeCode->exchange(
            $request->headers->get('X-Archilan-Timestamp', ''),
            $request->headers->get('X-Archilan-Signature', ''),
            $request->getContent(),
        );

        return new JsonResponse(['data' => [
            'userId' => $identity->userId,
            'eventId' => $identity->eventId,
            'displayName' => $identity->displayName,
            'state' => $identity->state,
            'exp' => $identity->expiresAt,
        ]]);
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
