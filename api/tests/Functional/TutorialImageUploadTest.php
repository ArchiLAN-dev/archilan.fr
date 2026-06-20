<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Shared\Infrastructure\NullMinioStorage;
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
        self::assertIsString($data['key']);
        self::assertStringStartsWith('tutorials/', $data['key']);
        self::assertStringEndsWith('.png', $data['key']);
        self::assertIsString($data['url']);
        self::assertStringContainsString($data['key'], $data['url']);
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
