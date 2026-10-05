<?php

declare(strict_types=1);

namespace App\Payments\Presentation\Controller;

use App\Payments\Application\Query\DonationCheckout;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Story 41.13: the donation form to embed. */
final readonly class DonationCheckoutController
{
    public function __construct(
        private DonationCheckout $donationCheckout,
    ) {
    }

    #[Route('/api/v1/donation/checkout', name: 'api_donation_checkout', methods: ['GET'])]
    public function checkout(): JsonResponse
    {
        return new JsonResponse([
            'data' => ['checkoutEmbedUrl' => $this->donationCheckout->getCheckoutEmbedUrl()],
            'meta' => [],
        ]);
    }
}
