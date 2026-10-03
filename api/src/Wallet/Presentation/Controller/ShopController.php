<?php

declare(strict_types=1);

namespace App\Wallet\Presentation\Controller;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use App\Wallet\Application\Command\BuyShopItem;
use App\Wallet\Application\Command\ManageShop;
use App\Wallet\Application\Query\ShopQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The cosmetics shop (story 41.7): what is on sale, buying, and the admin's listing.
 */
final readonly class ShopController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private ShopQuery $query,
        private BuyShopItem $buy,
        private ManageShop $manage,
    ) {
    }

    #[Route('/api/v1/shop', name: 'api_wallet_shop', methods: ['GET'])]
    public function catalog(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['items' => $this->query->catalog($user->getId())]);
    }

    #[Route('/api/v1/shop/items/{itemId}/buy', name: 'api_wallet_shop_buy', methods: ['POST'])]
    public function buy(Request $request, string $itemId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $this->buy->buy($user->getId(), $itemId);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/shop/items', name: 'api_wallet_admin_shop', methods: ['GET'])]
    public function admin(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse($this->query->admin());
    }

    #[Route('/api/v1/admin/shop/items', name: 'api_wallet_admin_shop_list', methods: ['POST'])]
    public function list(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
        }
        $price = $payload['price'] ?? null;

        $listed = $this->manage->list(
            is_string($payload['type'] ?? null) ? $payload['type'] : '',
            is_string($payload['cosmeticKey'] ?? null) ? $payload['cosmeticKey'] : '',
            is_int($price) ? $price : 0,
            $this->date($payload['availableFrom'] ?? null),
            $this->date($payload['availableUntil'] ?? null),
        );

        return new JsonResponse(['id' => $listed->id], 201);
    }

    #[Route('/api/v1/admin/shop/items/{itemId}', name: 'api_wallet_admin_shop_retire', methods: ['DELETE'])]
    public function retire(Request $request, string $itemId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->retire($itemId);

        return new JsonResponse(null, 204);
    }

    private function date(mixed $value): ?\DateTimeImmutable
    {
        if (!is_string($value) || '' === $value) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat(\DATE_ATOM, $value);

        return false === $date ? null : $date;
    }
}
