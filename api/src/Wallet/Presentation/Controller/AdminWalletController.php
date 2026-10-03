<?php

declare(strict_types=1);

namespace App\Wallet\Presentation\Controller;

use App\Shared\Application\Support\StatsPeriod;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use App\Wallet\Application\Command\AdjustMemberPelles;
use App\Wallet\Application\Query\PelleCirculationQueryInterface;
use App\Wallet\Application\Query\WalletQueryInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The admin side of the pelles (story 41.1): a member's wallet on their sheet, the credit/debit action
 * (AC7) and the circulation of the statistics page (AC8, story 42.1).
 */
final readonly class AdminWalletController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private WalletQueryInterface $wallets,
        private AdjustMemberPelles $adjust,
        private PelleCirculationQueryInterface $circulation,
        private ClockInterface $clock,
    ) {
    }

    #[Route('/api/v1/admin/users/{userId}/pelles', name: 'api_wallet_admin_user_wallet', methods: ['GET'])]
    public function wallet(Request $request, string $userId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse($this->wallets->walletOf($userId, $request->query->getInt('page', 1)));
    }

    #[Route('/api/v1/admin/users/{userId}/pelles', name: 'api_wallet_admin_user_adjust', methods: ['POST'])]
    public function adjust(Request $request, string $userId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
        }
        $amount = $payload['amount'] ?? null;
        $eventId = $payload['eventId'] ?? null;

        $recorded = $this->adjust->adjust(
            $admin->getId(),
            $userId,
            is_string($payload['direction'] ?? null) ? $payload['direction'] : '',
            is_int($amount) ? $amount : 0,
            is_string($payload['kind'] ?? null) ? $payload['kind'] : '',
            is_string($eventId) ? $eventId : null,
            is_string($payload['reason'] ?? null) ? $payload['reason'] : '',
        );

        return new JsonResponse([
            'movementId' => $recorded->movementId,
            'balanceBefore' => $recorded->balanceBefore,
            'balanceAfter' => $recorded->balanceAfter,
        ], 201);
    }

    /** The Pelles section of the admin statistics page (story 42.1, formerly /admin/pelles/circulation). */
    #[Route('/api/v1/admin/stats/pelles', name: 'api_wallet_admin_stats', methods: ['GET'])]
    public function circulation(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $period = StatsPeriod::fromCode($request->query->getString('period') ?: null, $this->clock->now());

        return new JsonResponse(['period' => $period->describe()] + $this->circulation->circulation($period));
    }
}
