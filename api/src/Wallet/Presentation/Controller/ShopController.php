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

    /** Story 41.12: open to visitors, who see the shop window. */
    #[Route('/api/v1/shop', name: 'api_wallet_shop', methods: ['GET'])]
    public function catalog(Request $request): JsonResponse
    {
        return new JsonResponse(['items' => $this->query->catalog($this->apiAccessGuard->optionalUser($request)?->getId())]);
    }

    #[Route('/api/v1/shop/items/{itemId}/buy', name: 'api_wallet_shop_buy', methods: ['POST'])]
    public function buy(Request $request, string $itemId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        // Story 41.14: the price the member saw, checked against the one in force (absent body: no check).
        $payload = json_decode($request->getContent(), true);
        $expected = is_array($payload) && is_int($payload['expectedPrice'] ?? null) ? $payload['expectedPrice'] : null;
        $this->buy->buy($user->getId(), $itemId, $expected);

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

    #[Route('/api/v1/admin/shop/items/{itemId}', name: 'api_wallet_admin_shop_edit', methods: ['PATCH'])]
    public function edit(Request $request, string $itemId): JsonResponse
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

        $this->manage->edit(
            $itemId,
            is_int($price) ? $price : 0,
            $this->date($payload['availableFrom'] ?? null),
            $this->date($payload['availableUntil'] ?? null),
        );

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/shop/items/{itemId}/pause', name: 'api_wallet_admin_shop_pause', methods: ['POST'])]
    public function pause(Request $request, string $itemId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->pause($itemId);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/shop/items/{itemId}/resume', name: 'api_wallet_admin_shop_resume', methods: ['POST'])]
    public function resume(Request $request, string $itemId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->resume($itemId);

        return new JsonResponse(null, 204);
    }

    /** Story 41.12: deletes the item for good; its buyers keep the cosmetic. */
    #[Route('/api/v1/admin/shop/items/{itemId}', name: 'api_wallet_admin_shop_delete', methods: ['DELETE'])]
    public function delete(Request $request, string $itemId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->delete($itemId);

        return new JsonResponse(null, 204);
    }

    /** Story 41.14: a temporary promotion on the item (price, optional start, mandatory end). */
    #[Route('/api/v1/admin/shop/items/{itemId}/promotion', name: 'api_wallet_admin_shop_promote', methods: ['PUT'])]
    public function promote(Request $request, string $itemId): JsonResponse
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

        $this->manage->promote(
            $itemId,
            is_int($price) ? $price : 0,
            $this->date($payload['startsAt'] ?? null),
            $this->date($payload['endsAt'] ?? null),
        );

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/shop/items/{itemId}/promotion', name: 'api_wallet_admin_shop_end_promotion', methods: ['DELETE'])]
    public function endPromotion(Request $request, string $itemId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->endPromotion($itemId);

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
