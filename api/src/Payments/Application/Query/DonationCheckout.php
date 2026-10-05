<?php

declare(strict_types=1);

namespace App\Payments\Application\Query;

use App\Payments\Application\Support\HelloAssoConfig;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Story 41.13: the HelloAsso donation form, embedded in the « Soutenir ArchiLAN » tab of the shop.
 */
final readonly class DonationCheckout
{
    public function __construct(
        private HelloAssoConfig $config,
        #[Autowire('%env(HELLOASSO_DONATION_FORM_SLUG)%')]
        private string $donationFormSlug,
    ) {
    }

    public function getCheckoutEmbedUrl(): ?string
    {
        if ('' === $this->donationFormSlug) {
            return null;
        }

        try {
            return $this->config->buildEmbedUrl(HelloAssoConfig::FORM_TYPE_DONATION, $this->donationFormSlug);
        } catch (\RuntimeException) {
            return null;
        }
    }
}
