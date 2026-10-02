<?php

declare(strict_types=1);

namespace App\Wallet\Presentation\Controller;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use App\Wallet\Application\Query\WalletQueryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * « Mon portefeuille » (story 41.1 AC5): private to its owner.
 */
final readonly class WalletController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private WalletQueryInterface $wallets,
    ) {
    }

    #[Route('/api/v1/me/wallet', name: 'api_wallet_me', methods: ['GET'])]
    public function mine(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse($this->wallets->walletOf($user->getId(), $request->query->getInt('page', 1)));
    }
}
