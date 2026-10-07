<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Payments\Application\Query\DonationCheckout;
use App\Payments\Application\Support\HelloAssoConfig;

/**
 * Story 41.13: the donation form of the « Soutenir ArchiLAN » tab.
 */
final class DonationCheckoutTest extends FunctionalTestCase
{
    public function testTheDonationFormIsNullUntilConfigured(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/donation/checkout');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertNull($response['data']['checkoutEmbedUrl']);
    }

    public function testTheDonationFormFollowsTheHelloAssoDonationPath(): void
    {
        self::getContainer()->set(
            DonationCheckout::class,
            new DonationCheckout(new HelloAssoConfig('client-id', 'secret', 'archilan', true), '1'),
        );

        $this->client->jsonRequest('GET', '/api/v1/donation/checkout');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame('https://www.helloasso-sandbox.com/associations/archilan/formulaires/1/widget', $response['data']['checkoutEmbedUrl']);
    }

    public function testADonationFormTypeMapsBothWays(): void
    {
        self::assertSame(HelloAssoConfig::FORM_TYPE_DONATION, HelloAssoConfig::fromApiFormType('Donation'));
    }
}
