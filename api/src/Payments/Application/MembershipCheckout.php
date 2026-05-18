<?php

declare(strict_types=1);

namespace App\Payments\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class MembershipCheckout
{
    public function __construct(
        private HelloAssoConfig $config,
        #[Autowire('%env(HELLOASSO_MEMBERSHIP_FORM_SLUG)%')]
        private string $membershipFormSlug,
    ) {
    }

    public function getCheckoutEmbedUrl(?string $email = null): ?string
    {
        if ('' === $this->membershipFormSlug) {
            return null;
        }

        try {
            return $this->config->buildEmbedUrl(HelloAssoConfig::FORM_TYPE_MEMBERSHIP, $this->membershipFormSlug, $email);
        } catch (\RuntimeException) {
            return null;
        }
    }
}
