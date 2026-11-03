<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Shared\Infrastructure\Double\NullMinioStorage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class TutorialImageUploadTest extends FunctionalTestCase
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

    public function testUploadStoresImageAndReturnsKeyAndUrl(): void
    {
        $this->loginAs($this->createUser('member@example.org', ['ROLE_USER']));

        $this->client->request('POST', '/api/v1/tutorial-images', [], ['file' => $this->pngUpload()]);
        self::assertResponseStatusCodeSame(200);

        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        $key = $data['key'];
        self::assertIsString($key);
        self::assertStringStartsWith('tutorials/', $key);
        self::assertStringEndsWith('.png', $key);
        // The returned URL is the stable serving route, not a presigned one: it gets written into the
        // markdown description and must never expire (story 31.11).
        self::assertIsString($data['url']);
        self::assertStringContainsString('/api/v1/tutorial-images/'.basename($key), $data['url']);
        self::assertStringNotContainsString('X-Amz-Signature', $data['url']);
    }

    public function testAnUnconfirmedAccountCannotUpload(): void
    {
        $this->loginAs($this->createUser('fresh@example.org', ['ROLE_USER'], emailVerified: false));

        $this->client->request('POST', '/api/v1/tutorial-images', [], ['file' => $this->pngUpload()]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAccountRunsOutOfItsQuotaButNotAnAdmin(): void
    {
        $this->client->disableReboot();
        $member = $this->createUser('member@example.org', ['ROLE_USER']);
        $this->loginAs($member);
        for ($upload = 0; $upload < 30; ++$upload) {
            $this->client->request('POST', '/api/v1/tutorial-images', [], ['file' => $this->pngUpload()]);
            self::assertResponseStatusCodeSame(200);
        }

        $this->client->request('POST', '/api/v1/tutorial-images', [], ['file' => $this->pngUpload()]);
        self::assertResponseStatusCodeSame(429);

        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        for ($upload = 0; $upload < 31; ++$upload) {
            $this->client->request('POST', '/api/v1/tutorial-images', [], ['file' => $this->pngUpload()]);
            self::assertResponseStatusCodeSame(200);
        }
    }

    public function testAnImageBeyondThePixelLimitIsRefused(): void
    {
        $this->loginAs($this->createUser('member@example.org', ['ROLE_USER']));
        // A GIF header is enough to declare 65535 x 65535 in a few bytes.
        $path = (string) tempnam(sys_get_temp_dir(), 'tut');
        file_put_contents($path, 'GIF89a'.pack('vv', 65535, 65535)."\0\0\0".','.pack('vvvv', 0, 0, 65535, 65535)."\0\x02\x01\x00\x00;");

        $this->client->request('POST', '/api/v1/tutorial-images', [], ['file' => new UploadedFile($path, 'big.gif', 'image/gif', null, true)]);

        self::assertResponseStatusCodeSame(422);
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        self::assertSame('image_too_many_pixels', $error['code'] ?? null);
    }

    public function testRejectsNonImage(): void
    {
        $this->loginAs($this->createUser('member@example.org', ['ROLE_USER']));

        $tmp = (string) tempnam(sys_get_temp_dir(), 'tut_');
        file_put_contents($tmp, 'this is not an image');
        $file = new UploadedFile($tmp, 'note.txt', 'text/plain', null, true);

        $this->client->request('POST', '/api/v1/tutorial-images', [], ['file' => $file]);
        self::assertResponseStatusCodeSame(422);
    }

    public function testRequiresAuthentication(): void
    {
        $this->client->request('POST', '/api/v1/tutorial-images', [], ['file' => $this->pngUpload()]);
        self::assertResponseStatusCodeSame(401);
    }

    private function pngUpload(): UploadedFile
    {
        $tmp = (string) tempnam(sys_get_temp_dir(), 'tut_');
        file_put_contents($tmp, (string) base64_decode(self::PNG_BASE64, true));

        return new UploadedFile($tmp, 'shot.png', 'image/png', null, true);
    }
}
