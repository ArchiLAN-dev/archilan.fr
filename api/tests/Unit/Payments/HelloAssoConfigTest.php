<?php

declare(strict_types=1);

namespace App\Tests\Unit\Payments;

use App\Payments\Application\HelloAssoConfig;
use PHPUnit\Framework\TestCase;

final class HelloAssoConfigTest extends TestCase
{
    public function testGetClientIdThrowsWhenEmpty(): void
    {
        $config = new HelloAssoConfig('', 'secret', 'my-org', true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/HELLOASSO_CLIENT_ID/');

        $config->getClientId();
    }

    public function testGetClientSecretThrowsWhenEmpty(): void
    {
        $config = new HelloAssoConfig('client-id', '', 'my-org', true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/HELLOASSO_CLIENT_SECRET/');

        $config->getClientSecret();
    }

    public function testGetOrganizationSlugThrowsWhenEmpty(): void
    {
        $config = new HelloAssoConfig('client-id', 'secret', '', true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/HELLOASSO_ORGANIZATION_SLUG/');

        $config->getOrganizationSlug();
    }

    public function testGetClientIdReturnsValueWhenConfigured(): void
    {
        $config = new HelloAssoConfig('my-client-id', 'secret', 'my-org', true);

        self::assertSame('my-client-id', $config->getClientId());
    }

    public function testAssertApiAccessConfiguredRequiresAllServerSideValues(): void
    {
        $config = new HelloAssoConfig('my-client-id', 'secret', '', true);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/HELLOASSO_ORGANIZATION_SLUG/');

        $config->assertApiAccessConfigured();
    }

    public function testAssertApiAccessConfiguredPassesWhenServerSideValuesExist(): void
    {
        $config = new HelloAssoConfig('my-client-id', 'secret', 'my-org', true);

        $config->assertApiAccessConfigured();

        self::assertSame('my-org', $config->getOrganizationSlug());
    }

    public function testGetApiBaseUrlReturnsSandboxUrl(): void
    {
        $config = new HelloAssoConfig('id', 'secret', 'org', true);

        self::assertStringContainsString('sandbox', $config->getApiBaseUrl());
        self::assertStringContainsString('sandbox', $config->getOAuthBaseUrl());
    }

    public function testGetApiBaseUrlReturnsProductionUrl(): void
    {
        $config = new HelloAssoConfig('id', 'secret', 'org', false);

        self::assertStringNotContainsString('sandbox', $config->getApiBaseUrl());
        self::assertStringNotContainsString('sandbox', $config->getOAuthBaseUrl());
    }

    public function testIsSandboxReflectsConfiguration(): void
    {
        self::assertTrue((new HelloAssoConfig('id', 's', 'org', true))->isSandbox());
        self::assertFalse((new HelloAssoConfig('id', 's', 'org', false))->isSandbox());
    }

    public function testBuildEmbedUrlReturnsSandboxUrl(): void
    {
        $config = new HelloAssoConfig('id', 'secret', 'archilan', true);

        $url = $config->buildEmbedUrl(HelloAssoConfig::FORM_TYPE_EVENT, 'spring-2027');

        self::assertStringContainsString('helloasso-sandbox.com', $url);
        self::assertStringContainsString('/associations/archilan/evenements/spring-2027/widget', $url);
    }

    public function testBuildEmbedUrlReturnsProductionUrl(): void
    {
        $config = new HelloAssoConfig('id', 'secret', 'archilan', false);

        $url = $config->buildEmbedUrl(HelloAssoConfig::FORM_TYPE_MEMBERSHIP, 'adhesion-2027');

        self::assertStringNotContainsString('sandbox', $url);
        self::assertStringContainsString('/associations/archilan/adhesions/adhesion-2027/widget', $url);
    }

    public function testBuildEmbedUrlThrowsWhenOrgSlugNotConfigured(): void
    {
        $config = new HelloAssoConfig('id', 'secret', '', true);

        $this->expectException(\RuntimeException::class);

        $config->buildEmbedUrl(HelloAssoConfig::FORM_TYPE_EVENT, 'some-form');
    }
}
