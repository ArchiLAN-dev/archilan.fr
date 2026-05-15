<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Payments\Application\HelloAssoConfig;
use App\Payments\Application\ShopCheckout;

final class ShopCheckoutTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testCheckoutEndpointIsPublic(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/shop/checkout');

        self::assertResponseIsSuccessful();
    }

    public function testCheckoutEmbedUrlIsNullWhenNotConfigured(): void
    {
        // HELLOASSO_SHOP_FORM_SLUG is empty in the test environment - null is the expected safe default.
        $this->client->jsonRequest('GET', '/api/v1/shop/checkout');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertNull($response['data']['checkoutEmbedUrl']);
    }

    public function testCheckoutEmbedUrlUsesConfiguredShopForm(): void
    {
        self::getContainer()->set(
            ShopCheckout::class,
            new ShopCheckout(
                new HelloAssoConfig('client-id', 'secret', 'archilan', true),
                'boutique-2027',
            ),
        );

        $this->client->jsonRequest('GET', '/api/v1/shop/checkout');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame(
            'https://www.helloasso-sandbox.com/associations/archilan/boutiques/boutique-2027/widget',
            $response['data']['checkoutEmbedUrl'],
        );
    }

    public function testResponseFollowsApiShape(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/shop/checkout');

        $response = $this->decodedJsonResponse();
        self::assertArrayHasKey('data', $response);
        self::assertArrayHasKey('meta', $response);
        self::assertIsArray($response['data']);
        self::assertArrayHasKey('checkoutEmbedUrl', $response['data']);
    }
}
