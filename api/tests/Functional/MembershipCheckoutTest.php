<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Payments\Application\HelloAssoConfig;
use App\Payments\Application\MembershipCheckout;

final class MembershipCheckoutTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
    }

    public function testCheckoutEndpointIsPublic(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/membership/checkout');

        self::assertResponseIsSuccessful();
    }

    public function testCheckoutEmbedUrlIsNullWhenNotConfigured(): void
    {
        // HELLOASSO_MEMBERSHIP_FORM_SLUG is empty in the test environment - null is the expected safe default.
        $this->client->jsonRequest('GET', '/api/v1/membership/checkout');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertNull($response['data']['checkoutEmbedUrl']);
    }

    public function testCheckoutEmbedUrlUsesConfiguredMembershipForm(): void
    {
        self::getContainer()->set(
            MembershipCheckout::class,
            new MembershipCheckout(
                new HelloAssoConfig('client-id', 'secret', 'archilan', true),
                'adhesion-2027',
            ),
        );

        $this->client->jsonRequest('GET', '/api/v1/membership/checkout');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame(
            'https://www.helloasso-sandbox.com/associations/archilan/adhesions/adhesion-2027/widget',
            $response['data']['checkoutEmbedUrl'],
        );
    }

    public function testResponseFollowsApiShape(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/membership/checkout');

        $response = $this->decodedJsonResponse();
        self::assertArrayHasKey('data', $response);
        self::assertArrayHasKey('meta', $response);
        self::assertIsArray($response['data']);
        self::assertArrayHasKey('checkoutEmbedUrl', $response['data']);
    }
}
