<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Story 30.41. The intensity of the banner preset laid over a banner image: 0 to 100, 50 by default, saved with
 * the profile and shown on it.
 */
final class CommunityBannerOverlayTest extends FunctionalTestCase
{
    public function testTheIntensityStartsAtHalfAndIsSavedWithTheProfile(): void
    {
        $this->loginAs($this->createUser('ivy@example.org', slug: 'ivy'));

        $this->client->jsonRequest('GET', '/api/v1/community/profile');
        self::assertSame(50, $this->data()['bannerOverlay']);

        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['audience' => 'public', 'bannerOverlay' => 30]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(30, $this->data()['bannerOverlay']);

        $this->client->getCookieJar()->clear();
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/ivy');
        $customization = $this->data()['customization'];
        self::assertIsArray($customization);
        self::assertSame(30, $customization['bannerOverlay']);
    }

    public function testAnOmittedIntensityIsKept(): void
    {
        $this->loginAs($this->createUser('ian@example.org', slug: 'ian'));
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['bannerOverlay' => 80]);
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['bannerPreset' => 'neon']);

        self::assertResponseStatusCodeSame(200);
        self::assertSame(80, $this->data()['bannerOverlay']);
    }

    public function testAnIntensityOutOfBoundsIsRefused(): void
    {
        $this->loginAs($this->createUser('ida@example.org', slug: 'ida'));

        foreach ([101, -1, '50', 12.5] as $invalid) {
            $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['bannerOverlay' => $invalid]);
            self::assertResponseStatusCodeSame(422);
        }
    }

    /**
     * @return array<mixed>
     */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }
}
