<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\ProfileBannerDefinition;
use App\Identity\Domain\Entity\User;
use App\Membership\Domain\Entity\Membership;
use App\Wallet\Domain\Entity\OwnedCosmetic;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Story 41.11: profile banners managed from the admin, animated ones included.
 */
final class ProfileBannerCatalogTest extends FunctionalTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
    }

    public function testThePublicCatalogListsThePresets(): void
    {
        $this->client->request('GET', '/api/v1/profile-banners');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('max-age=300', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $banners = $this->banners();
        self::assertCount(10, $banners);
        self::assertSame(['key' => 'default', 'label' => 'Défaut', 'access' => 'free', 'builtIn' => true, 'media' => null], $banners[0]);
    }

    public function testAnAdminUploadsAStillBanner(): void
    {
        $this->loginAs($this->admin);

        $this->upload('dunes', 'Dunes', 'free', ['image' => $this->file($this->webp(1500, 400), 'dunes.webp')]);

        self::assertResponseStatusCodeSame(201);
        $media = $this->published('dunes')['media'] ?? null;
        self::assertIsArray($media);
        self::assertIsString($media['image'] ?? null);
        self::assertStringContainsString('profile-banners/dunes-image-', $media['image']);
        self::assertStringContainsString('.webp?', $media['image']);
        self::assertNull($media['webm']);
        self::assertNull($media['mp4']);
    }

    public function testAnAdminUploadsAnAnimatedBanner(): void
    {
        $this->loginAs($this->admin);

        $this->upload('rain', 'Pluie', 'free', [
            'image' => $this->file($this->jpeg(1600, 400), 'rain.jpg'),
            'webm' => $this->file("\x1A\x45\xDF\xA3".str_repeat("\0", 64), 'rain.webm'),
            'mp4' => $this->file("\0\0\0\x18ftypmp42".str_repeat("\0", 64), 'rain.mp4'),
        ]);

        self::assertResponseStatusCodeSame(201);
        $media = $this->published('rain')['media'] ?? null;
        self::assertIsArray($media);
        self::assertIsString($media['image'] ?? null);
        self::assertStringContainsString('.jpg?', $media['image']);
        self::assertIsString($media['webm'] ?? null);
        self::assertStringContainsString('profile-banners/rain-webm-', $media['webm']);
        self::assertIsString($media['mp4'] ?? null);
    }

    public function testFilesThatDoNotFitAreRefusedWithTheReason(): void
    {
        $this->loginAs($this->admin);

        $this->upload('rain', 'Pluie', 'free', [
            'image' => $this->file($this->webp(1200, 600), 'rain.webp'),
            'webm' => $this->file("\x1A\x45\xDF\xA3".str_repeat("\0", 64), 'rain.webm'),
        ]);

        self::assertResponseStatusCodeSame(422);
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame([
            'image' => ["L'image fixe doit être de 3 à 6 fois plus large que haute."],
            'mp4' => ['Une bannière animée a besoin des deux vidéos, WebM et MP4.'],
        ], $error['details'] ?? null);
        self::assertCount(0, $this->entityManager->getRepository(ProfileBannerDefinition::class)->findAll());
    }

    public function testAPresetKeyCannotBeTaken(): void
    {
        $this->loginAs($this->admin);

        $this->upload('sunset', 'Autre', 'free', ['image' => $this->file($this->webp(1500, 400), 'b.webp')]);

        self::assertResponseStatusCodeSame(409);
    }

    public function testAccessDecidesWhoPicksTheBanner(): void
    {
        $member = $this->createUser('member@example.org', ['ROLE_USER'], 'Member');
        $this->entityManager->persist(Membership::create($member->getId(), new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), new \DateTimeImmutable('2099-01-01T00:00:00+00:00'), 'admin', null, null, new \DateTimeImmutable('2026-01-01T00:00:00+00:00')));
        $plain = $this->createUser('plain@example.org', ['ROLE_USER'], 'Plain');
        $this->loginAs($this->admin);
        $this->upload('dunes', 'Dunes', 'members', ['image' => $this->file($this->webp(1500, 400), 'dunes.webp')]);
        $this->upload('nova', 'Nova', 'shop', ['image' => $this->file($this->webp(1500, 400), 'nova.webp')]);

        $this->loginAs($plain);
        $this->pick('dunes');
        self::assertResponseStatusCodeSame(422);
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame(['bannerPreset' => ['Bannière réservée aux adhérents.']], $error['details'] ?? null);
        $this->pick('nova');
        self::assertResponseStatusCodeSame(422);

        $this->loginAs($member);
        $this->pick('dunes');
        self::assertResponseIsSuccessful();

        $this->entityManager->persist(OwnedCosmetic::acquire($plain->getId(), 'banner', 'nova', new \DateTimeImmutable('2026-10-03T10:00:00+00:00')));
        $this->entityManager->flush();
        $this->loginAs($plain);
        $this->pick('nova');
        self::assertResponseIsSuccessful();
        self::assertSame('nova', $this->profile()['bannerPreset'] ?? null);
    }

    public function testABannerAlreadyShownIsKeptOnALaterSave(): void
    {
        $plain = $this->createUser('plain@example.org', ['ROLE_USER'], 'Plain');
        $this->loginAs($plain);
        $this->pick('sunset');
        self::assertResponseIsSuccessful();

        $this->loginAs($this->admin);
        $this->client->jsonRequest('PATCH', '/api/v1/admin/profile-banners/sunset', ['access' => 'members']);
        self::assertResponseStatusCodeSame(204);

        $this->loginAs($plain);
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['bannerPreset' => 'sunset', 'bio' => 'Nouvelle bio']);
        self::assertResponseIsSuccessful();
        $this->pick('forest');
        $this->pick('sunset');
        self::assertResponseStatusCodeSame(422);
    }

    public function testARetiredBannerFallsBackOnTheDefaultOne(): void
    {
        $plain = $this->createUser('plain@example.org', ['ROLE_USER'], 'Plain');
        $this->loginAs($plain);
        $this->pick('neon');

        $this->loginAs($this->admin);
        $this->client->request('POST', '/api/v1/admin/profile-banners/neon/retire');
        self::assertResponseStatusCodeSame(204);
        $this->client->request('POST', '/api/v1/admin/profile-banners/default/retire');
        self::assertResponseStatusCodeSame(422);

        $this->loginAs($plain);
        self::assertSame('default', $this->profile()['bannerPreset'] ?? null, 'the key stays stored, the default banner shows');
        $this->client->request('GET', '/api/v1/profile-banners');
        self::assertNull($this->published('neon'));
    }

    public function testAShopBannerCanBePutOnSale(): void
    {
        $this->loginAs($this->admin);
        $this->upload('nova', 'Nova', 'shop', ['image' => $this->file($this->webp(1500, 400), 'nova.webp')]);

        $this->client->request('GET', '/api/v1/admin/shop/items');
        $sellable = $this->decodedJsonResponse()['sellable'] ?? null;
        self::assertIsArray($sellable);
        self::assertSame(['nova'], $sellable['banner'] ?? null);

        $this->client->jsonRequest('POST', '/api/v1/admin/shop/items', ['type' => 'banner', 'cosmeticKey' => 'nova', 'price' => 50]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testOnlyAnAdminManagesTheBanners(): void
    {
        $this->loginAs($this->createUser('plain@example.org', ['ROLE_USER'], 'Plain'));

        $this->client->request('GET', '/api/v1/admin/profile-banners');
        self::assertResponseStatusCodeSame(403);
        $this->upload('dunes', 'Dunes', 'free', ['image' => $this->file($this->webp(1500, 400), 'dunes.webp')]);
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param array<string, UploadedFile> $files
     */
    private function upload(string $key, string $label, string $access, array $files): void
    {
        $this->client->request('POST', '/api/v1/admin/profile-banners', ['key' => $key, 'label' => $label, 'access' => $access], $files);
    }

    private function pick(string $banner): void
    {
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['bannerPreset' => $banner]);
    }

    /**
     * @return array<mixed>
     */
    private function profile(): array
    {
        $this->client->request('GET', '/api/v1/community/profile');
        $body = $this->decodedJsonResponse();
        $data = $body['data'] ?? $body;
        self::assertIsArray($data);

        return $data;
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function webp(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        ob_start();
        imagewebp($image);

        return (string) ob_get_clean();
    }

    /**
     * @param positive-int $width
     * @param positive-int $height
     */
    private function jpeg(int $width, int $height): string
    {
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        ob_start();
        imagejpeg($image);

        return (string) ob_get_clean();
    }

    private function file(string $bytes, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'banner');
        self::assertIsString($path);
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function published(string $key): ?array
    {
        foreach ($this->banners() as $banner) {
            if ($key === ($banner['key'] ?? null)) {
                return $banner;
            }
        }

        return null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function banners(): array
    {
        if ('GET' !== $this->client->getRequest()->getMethod() || '/api/v1/profile-banners' !== $this->client->getRequest()->getPathInfo()) {
            $this->client->request('GET', '/api/v1/profile-banners');
        }
        $banners = $this->decodedJsonResponse()['banners'] ?? null;
        self::assertIsArray($banners);
        $list = [];
        foreach ($banners as $banner) {
            self::assertIsArray($banner);
            $row = [];
            foreach ($banner as $field => $value) {
                $row[(string) $field] = $value;
            }
            $list[] = $row;
        }

        return $list;
    }
}
