<?php

declare(strict_types=1);

namespace App\Payments\Presentation;

use App\Payments\Application\MembershipCheckout;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class MembershipCheckoutController
{
    public function __construct(
        private MembershipCheckout $membershipCheckout,
    ) {
    }

    #[Route('/api/v1/membership/checkout', name: 'api_membership_checkout', methods: ['GET'])]
    public function checkout(): JsonResponse
    {
        return new JsonResponse([
            'data' => ['checkoutEmbedUrl' => $this->membershipCheckout->getCheckoutEmbedUrl()],
            'meta' => [],
        ]);
    }
}
