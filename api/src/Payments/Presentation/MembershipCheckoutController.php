<?php

declare(strict_types=1);

namespace App\Payments\Presentation;

use App\Payments\Application\MembershipCheckout;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class MembershipCheckoutController
{
    public function __construct(
        private MembershipCheckout $membershipCheckout,
        private ApiAccessGuard $apiAccessGuard,
    ) {
    }

    #[Route('/api/v1/membership/checkout', name: 'api_membership_checkout', methods: ['GET'])]
    public function checkout(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->optionalUser($request);

        return new JsonResponse([
            'data' => ['checkoutEmbedUrl' => $this->membershipCheckout->getCheckoutEmbedUrl($user?->getEmail())],
            'meta' => [],
        ]);
    }
}
