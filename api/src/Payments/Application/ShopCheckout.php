<?php

declare(strict_types=1);

namespace App\Payments\Application;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

final readonly class ShopCheckout
{
    public function __construct(
        private HelloAssoConfig $config,
        #[Autowire('%env(HELLOASSO_SHOP_FORM_SLUG)%')]
        private string $shopFormSlug,
    ) {
    }

    public function getCheckoutEmbedUrl(): ?string
    {
        if ('' === $this->shopFormSlug) {
            return null;
        }

        try {
            return $this->config->buildEmbedUrl(HelloAssoConfig::FORM_TYPE_SHOP, $this->shopFormSlug);
        } catch (\RuntimeException) {
            return null;
        }
    }
}
