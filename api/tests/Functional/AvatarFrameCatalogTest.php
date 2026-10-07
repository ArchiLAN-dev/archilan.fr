<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\AvatarFrameDefinition;
use App\Identity\Domain\Entity\User;
use App\Membership\Domain\Entity\Membership;
use App\Wallet\Domain\Entity\OwnedCosmetic;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * Story 41.10: video frames managed from the admin.
 */
final class AvatarFrameCatalogTest extends FunctionalTestCase
{
    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
    }

    public function testThePublicCatalogListsTheBuiltInFrames(): void
    {
        $this->client->request('GET', '/api/v1/avatar-frames');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('max-age=300', (string) $this->client->getResponse()->headers->get('Cache-Control'));
        $frames = $this->frames();
        self::assertCount(7, $frames);
        self::assertSame(['key' => 'fire', 'label' => 'Feu', 'access' => 'admins', 'builtIn' => true, 'video' => null], $frames[0]);
    }

    public function testAnAdminUploadsAFrameWithItsFourFiles(): void
    {
        $this->loginAs($this->admin);

        $this->upload('comet', 'Comète', 'free');

        self::assertResponseStatusCodeSame(201);
        $this->client->request('GET', '/api/v1/avatar-frames');
        $comet = array_values(array_filter($this->frames(), static fn (array $f): bool => 'comet' === ($f['key'] ?? null)))[0] ?? null;
        self::assertIsArray($comet);
        self::assertSame('Comète', $comet['label']);
        self::assertFalse($comet['builtIn']);
        $video = $comet['video'] ?? null;
        self::assertIsArray($video);
        self::assertIsString($video['webm'] ?? null);
        self::assertStringContainsString('avatar-frames/comet-webm-', $video['webm']);
        self::assertArrayHasKey('shade', $video);
        self::assertNull($video['shade'], 'no shade unless given');
    }

    public function testAFrameGetsItsShadeAtUploadOrLater(): void
    {
        // Story 41.30: the dark parts of a frame, laid in `multiply` under its light.
        $this->loginAs($this->admin);

        $this->upload('envy', 'Mains de l\'Envie', 'free', shade: true);
        self::assertResponseStatusCodeSame(201);
        $shade = $this->shadeOf('envy');
        self::assertIsArray($shade);
        self::assertIsString($shade['webm'] ?? null);
        self::assertStringContainsString('avatar-frames/envy-shade-', $shade['webm']);
        self::assertIsString($shade['mp4'] ?? null);

        $this->client->request('DELETE', '/api/v1/admin/avatar-frames/envy/shade');
        self::assertResponseStatusCodeSame(204);
        self::assertNull($this->shadeOf('envy'));

        $this->client->request('POST', '/api/v1/admin/avatar-frames/envy/shade', [], ['shadeWebm' => $this->webmFile()]);
        self::assertResponseStatusCodeSame(422, 'both videos or none');
        $this->client->request('POST', '/api/v1/admin/avatar-frames/envy/shade', [], ['shadeWebm' => $this->webmFile(), 'shadeMp4' => $this->mp4File()]);
        self::assertResponseStatusCodeSame(204);
        self::assertIsArray($this->shadeOf('envy'));

        $this->client->request('POST', '/api/v1/admin/avatar-frames/fire/shade', [], ['shadeWebm' => $this->webmFile(), 'shadeMp4' => $this->mp4File()]);
        self::assertContains($this->client->getResponse()->getStatusCode(), [404, 409], 'a built-in frame keeps the code\'s files');

        $this->client->request('POST', '/api/v1/admin/avatar-frames', ['key' => 'half', 'label' => 'Moitié', 'access' => 'free'], [
            'webm' => $this->webmFile(), 'mp4' => $this->mp4File(), 'poster' => $this->file($this->webp(512), 'p.webp'), 'still' => $this->file($this->webp(512), 's.webp'), 'shadeMp4' => $this->mp4File(),
        ]);
        self::assertResponseStatusCodeSame(422, 'a shade at upload needs both videos too');
    }

    public function testFilesThatDoNotFitAreRefusedWithTheReason(): void
    {
        $this->loginAs($this->admin);

        $this->upload('comet', 'Comète', 'free', posterSize: 256);

        self::assertResponseStatusCodeSame(422);
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame(['poster' => ["L'aperçu doit mesurer 512 x 512 pixels."]], $error['details'] ?? null);
        self::assertCount(0, $this->entityManager->getRepository(AvatarFrameDefinition::class)->findAll());
    }

    public function testACodeFrameKeyCannotBeTaken(): void
    {
        $this->loginAs($this->admin);

        $this->upload('gold', 'Or bis', 'free');

        self::assertResponseStatusCodeSame(409);
    }

    public function testAccessDecidesWhoPicksTheFrame(): void
    {
        $member = $this->createUser('member@example.org', ['ROLE_USER'], 'Member');
        $this->entityManager->persist(Membership::create($member->getId(), new \DateTimeImmutable('2026-01-01T00:00:00+00:00'), new \DateTimeImmutable('2099-01-01T00:00:00+00:00'), 'admin', null, null, new \DateTimeImmutable('2026-01-01T00:00:00+00:00')));
        $plain = $this->createUser('plain@example.org', ['ROLE_USER'], 'Plain');
        $this->loginAs($this->admin);
        $this->upload('comet', 'Comète', 'members');
        $this->upload('nova', 'Nova', 'shop');

        $this->loginAs($plain);
        $this->pick('comet');
        self::assertResponseStatusCodeSame(422);
        $this->pick('nova');
        self::assertResponseStatusCodeSame(422);

        $this->loginAs($member);
        $this->pick('comet');
        self::assertResponseIsSuccessful();

        $this->entityManager->persist(OwnedCosmetic::acquire($plain->getId(), 'frame', 'nova', new \DateTimeImmutable('2026-10-03T10:00:00+00:00')));
        $this->entityManager->flush();
        $this->loginAs($plain);
        $this->pick('nova');
        self::assertResponseIsSuccessful();
    }

    public function testABuiltInFrameCanBeOpenedToEveryone(): void
    {
        $plain = $this->createUser('plain@example.org', ['ROLE_USER'], 'Plain');
        $this->loginAs($plain);
        $this->pick('fire');
        self::assertResponseStatusCodeSame(422);

        $this->loginAs($this->admin);
        $this->client->jsonRequest('PATCH', '/api/v1/admin/avatar-frames/fire', ['access' => 'free']);
        self::assertResponseStatusCodeSame(204);

        $this->loginAs($plain);
        $this->pick('fire');
        self::assertResponseIsSuccessful();
    }

    public function testARetiredFrameLeavesThePickerAndTheProfiles(): void
    {
        $this->loginAs($this->admin);
        $this->upload('comet', 'Comète', 'free');
        $plain = $this->createUser('plain@example.org', ['ROLE_USER'], 'Plain');
        $this->loginAs($plain);
        $this->pick('comet');
        self::assertResponseIsSuccessful();

        $this->loginAs($this->admin);
        $this->client->request('POST', '/api/v1/admin/avatar-frames/comet/retire');
        self::assertResponseStatusCodeSame(204);

        $this->loginAs($plain);
        $this->client->request('GET', '/api/v1/community/profile');
        $body = $this->decodedJsonResponse();
        $data = $body['data'] ?? $body;
        self::assertIsArray($data);
        self::assertArrayHasKey('avatarFrame', $data);
        self::assertNull($data['avatarFrame'], 'the key stays stored, the frame is not shown');
        $this->pick('comet');
        self::assertResponseStatusCodeSame(422);
    }

    public function testAShopFrameCanBePutOnSale(): void
    {
        $this->loginAs($this->admin);
        $this->upload('nova', 'Nova', 'shop');

        $this->client->request('GET', '/api/v1/admin/shop/items');
        $sellable = $this->decodedJsonResponse()['sellable'] ?? null;
        self::assertIsArray($sellable);
        self::assertSame(['nova'], $sellable['frame'] ?? null);

        $this->client->jsonRequest('POST', '/api/v1/admin/shop/items', ['type' => 'frame', 'cosmeticKey' => 'nova', 'price' => 50]);
        self::assertResponseStatusCodeSame(201);
    }

    public function testOnlyAnAdminManagesTheFrames(): void
    {
        $this->loginAs($this->createUser('plain@example.org', ['ROLE_USER'], 'Plain'));

        $this->client->request('GET', '/api/v1/admin/avatar-frames');
        self::assertResponseStatusCodeSame(403);
        $this->upload('comet', 'Comète', 'free');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @param positive-int $posterSize
     */
    private function upload(string $key, string $label, string $access, int $posterSize = 512, bool $shade = false): void
    {
        $files = [
            'webm' => $this->webmFile(),
            'mp4' => $this->mp4File(),
            'poster' => $this->file($this->webp($posterSize), 'poster.webp'),
            'still' => $this->file($this->webp(512), 'still.webp'),
        ];
        if ($shade) {
            $files['shadeWebm'] = $this->webmFile();
            $files['shadeMp4'] = $this->mp4File();
        }
        $this->client->request('POST', '/api/v1/admin/avatar-frames', ['key' => $key, 'label' => $label, 'access' => $access], $files);
    }

    private function webmFile(): UploadedFile
    {
        return $this->file("\x1A\x45\xDF\xA3".str_repeat("\0", 64), 'frame.webm');
    }

    private function mp4File(): UploadedFile
    {
        return $this->file("\0\0\0\x18ftypmp42".str_repeat("\0", 64), 'frame.mp4');
    }

    /** @return array<mixed>|null the published shade of a frame */
    private function shadeOf(string $key): ?array
    {
        $this->client->request('GET', '/api/v1/admin/avatar-frames');
        foreach ($this->frames() as $frame) {
            if ($key === ($frame['key'] ?? null)) {
                $video = $frame['video'] ?? null;
                self::assertIsArray($video);
                $shade = $video['shade'] ?? null;

                return is_array($shade) ? $shade : null;
            }
        }
        self::fail('frame not listed: '.$key);
    }

    private function pick(string $frame): void
    {
        $this->client->jsonRequest('PUT', '/api/v1/community/profile', ['avatarFrame' => $frame]);
    }

    /**
     * @param positive-int $size
     */
    private function webp(int $size): string
    {
        $image = imagecreatetruecolor($size, $size);
        self::assertNotFalse($image);
        ob_start();
        imagewebp($image);

        return (string) ob_get_clean();
    }

    private function file(string $bytes, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'frame');
        self::assertIsString($path);
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, null, null, true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function frames(): array
    {
        $frames = $this->decodedJsonResponse()['frames'] ?? null;
        self::assertIsArray($frames);
        $list = [];
        foreach ($frames as $frame) {
            self::assertIsArray($frame);
            $row = [];
            foreach ($frame as $field => $value) {
                $row[(string) $field] = $value;
            }
            $list[] = $row;
        }

        return $list;
    }
}
