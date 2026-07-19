<?php

declare(strict_types=1);

namespace App\Payments\Presentation\Controller;

use App\Payments\Application\Query\ShopCheckout;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ShopCheckoutController
{
    public function __construct(
        private ShopCheckout $shopCheckout,
    ) {
    }

    #[Route('/api/v1/shop/checkout', name: 'api_shop_checkout', methods: ['GET'])]
    public function checkout(): JsonResponse
    {
        return new JsonResponse([
            'data' => ['checkoutEmbedUrl' => $this->shopCheckout->getCheckoutEmbedUrl()],
            'meta' => [],
        ]);
    }
}
