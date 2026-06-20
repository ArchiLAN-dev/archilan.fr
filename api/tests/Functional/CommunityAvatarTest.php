<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Shared\Infrastructure\NullMinioStorage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class CommunityAvatarTest extends FunctionalTestCase
{
    // 1x1 transparent PNG.
    private const string PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        NullMinioStorage::reset();
    }

    protected function tearDown(): void
    {
        NullMinioStorage::reset();
        parent::tearDown();
    }

    public function testUploadStoresAvatarAndReturnsPresignedUrl(): void
    {
        $this->loginAs($this->createUser('amy@example.org', slug: 'amy'));

        $this->client->request('POST', '/api/v1/community/profile/avatar', [], ['file' => $this->pngUpload()]);
        self::assertResponseStatusCodeSame(200);

        $url = $this->data()['avatarUrl'] ?? null;
        self::assertIsString($url);
        self::assertStringContainsString('community/avatars/', $url, 'returns a presigned URL for the uploaded object');
    }

    public function testUploadedAvatarSurfacesOnEditorAndPublicProfile(): void
    {
        $user = $this->createUser('bea@example.org', slug: 'bea');
        $this->loginAs($user);

        $this->client->request('POST', '/api/v1/community/profile/avatar', [], ['file' => $this->pngUpload()]);
        self::assertResponseStatusCodeSame(200);

        // Editor read exposes the resolved URL + the custom-avatar flag.
        $this->client->jsonRequest('GET', '/api/v1/community/profile');
        $editor = $this->data();
        self::assertTrue($editor['hasCustomAvatar']);
        self::assertIsString($editor['avatarUrl']);
        self::assertStringContainsString('community/avatars/', $editor['avatarUrl']);

        // Public profile shows the same custom avatar (presigned), to anonymous viewers too.
        $this->client->getCookieJar()->clear();
        $this->client->jsonRequest('GET', '/api/v1/community/profiles/bea');
        $avatarUrl = $this->data()['avatarUrl'] ?? null;
        self::assertIsString($avatarUrl);
        self::assertStringContainsString('community/avatars/', $avatarUrl);
    }

    public function testRemoveClearsCustomAvatar(): void
    {
        $user = $this->createUser('cleo@example.org', slug: 'cleo');
        $this->loginAs($user);

        $this->client->request('POST', '/api/v1/community/profile/avatar', [], ['file' => $this->pngUpload()]);
        self::assertResponseStatusCodeSame(200);

        $this->client->jsonRequest('DELETE', '/api/v1/community/profile/avatar');
        self::assertResponseStatusCodeSame(200);
        // No external source linked → resolves to null (frontend renders the default).
        self::assertNull($this->data()['avatarUrl']);

        $this->client->jsonRequest('GET', '/api/v1/community/profile');
        self::assertFalse($this->data()['hasCustomAvatar']);
        self::assertNull($this->data()['avatarUrl']);
    }

    public function testRejectsNonImage(): void
    {
        $this->loginAs($this->createUser('dan@example.org', slug: 'dan'));

        $tmp = (string) tempnam(sys_get_temp_dir(), 'avt_');
        file_put_contents($tmp, 'this is not an image');
        $file = new UploadedFile($tmp, 'note.txt', 'text/plain', null, true);

        $this->client->request('POST', '/api/v1/community/profile/avatar', [], ['file' => $file]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testUploadRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/v1/community/profile/avatar', [], ['file' => $this->pngUpload()]);
        self::assertResponseStatusCodeSame(401);
    }

    private function pngUpload(): UploadedFile
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'avt_');
        file_put_contents($tmp, (string) base64_decode(self::PNG_BASE64, true));

        return new UploadedFile($tmp, 'me.png', 'image/png', null, true);
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
