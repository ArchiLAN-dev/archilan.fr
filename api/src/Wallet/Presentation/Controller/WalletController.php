<?php

declare(strict_types=1);

namespace App\Wallet\Presentation\Controller;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use App\Wallet\Application\Query\MyWeeklyQuests;
use App\Wallet\Application\Query\MyWelcomeQuests;
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
        private MyWeeklyQuests $quests,
        private MyWelcomeQuests $welcome,
    ) {
    }

    /** The quests of the week (story 41.6). */
    #[Route('/api/v1/me/quests', name: 'api_wallet_me_quests', methods: ['GET'])]
    public function quests(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse($this->quests->of($user->getId()));
    }

    /** Story 41.25: the first steps of a newcomer, null when there are none to show. */
    #[Route('/api/v1/me/welcome-quests', name: 'api_wallet_me_welcome_quests', methods: ['GET'])]
    public function welcomeQuests(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['welcome' => $this->welcome->of($user->getId())]);
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
