<?php

declare(strict_types=1);

namespace App\Tests\Functional;

final class SecurityHeadersTest extends FunctionalTestCase
{
    public function testAnApiResponseCarriesTheSecurityHeaders(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/auth/me');

        self::assertResponseStatusCodeSame(401);
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
        self::assertResponseHeaderSame('Strict-Transport-Security', 'max-age=63072000; includeSubDomains');
        self::assertResponseHeaderNotSame('X-Powered-By', 'PHP');
    }

    public function testAnErrorPageCarriesThemToo(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/no-such-route');

        self::assertResponseStatusCodeSame(404);
        self::assertResponseHeaderSame('X-Content-Type-Options', 'nosniff');
    }
}
