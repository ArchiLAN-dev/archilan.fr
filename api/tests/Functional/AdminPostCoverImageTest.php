<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Content\Domain\Post;
use App\Shared\Infrastructure\NullMinioStorage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AdminPostCoverImageTest extends FunctionalTestCase
{
    private NullMinioStorage $minioStorage;

    protected function setUp(): void
    {
        parent::setUp();

        $minioStorage = self::getContainer()->get(NullMinioStorage::class);
        self::assertInstanceOf(NullMinioStorage::class, $minioStorage);
        $this->minioStorage = $minioStorage;

        NullMinioStorage::reset();
    }

    protected function tearDown(): void
    {
        NullMinioStorage::reset();
        parent::tearDown();
    }

    public function testUploadValidJpegStoresInMinioAndReturnsCoverUrl(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $postId = $this->createPost();

        $tmpFile = $this->createTempImage('image/jpeg');
        $uploadedFile = new UploadedFile($tmpFile, 'cover.jpg', 'image/jpeg', null, true);

        $this->client->request('POST', sprintf('/api/v1/admin/posts/%s/cover-image', $postId), [], ['file' => $uploadedFile]);

        self::assertResponseIsSuccessful();
        unlink($tmpFile);

        $expectedKey = sprintf('posts/%s/cover.jpg', $postId);
        self::assertTrue($this->minioStorage->exists('media', $expectedKey), 'Cover image should be stored in MinIO');

        $post = $this->entityManager->find(Post::class, $postId);
        self::assertInstanceOf(Post::class, $post);
        $this->entityManager->refresh($post);
        self::assertSame($expectedKey, $post->getCoverImageKey());

        $body = $this->decodedJsonResponse();
        $data = $this->responseData($body);
        self::assertIsString($data['coverImageUrl']);
        self::assertStringContainsString($expectedKey, $data['coverImageUrl']);
        self::assertSame($expectedKey, $data['coverImageKey']);
    }

    public function testUploadInvalidMimeReturns422(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $postId = $this->createPost();

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_cover_');
        file_put_contents($tmpFile, 'This is not an image');
        $uploadedFile = new UploadedFile($tmpFile, 'cover.txt', 'text/plain', null, true);

        $this->client->request('POST', sprintf('/api/v1/admin/posts/%s/cover-image', $postId), [], ['file' => $uploadedFile]);

        self::assertResponseStatusCodeSame(422);
        unlink($tmpFile);

        $body = $this->decodedJsonResponse();
        self::assertSame('image_invalid_type', $this->errorCode($body));
    }

    public function testUploadOversizedFileReturns422(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $postId = $this->createPost();

        $tmpFile = $this->createTempImage('image/jpeg');
        $uploadedFile = new UploadedFile($tmpFile, 'cover.jpg', 'image/jpeg', \UPLOAD_ERR_INI_SIZE, true);

        $this->client->request('POST', sprintf('/api/v1/admin/posts/%s/cover-image', $postId), [], ['file' => $uploadedFile]);

        self::assertResponseStatusCodeSame(422);
        unlink($tmpFile);

        $body = $this->decodedJsonResponse();
        self::assertSame('image_too_large', $this->errorCode($body));
    }

    public function testUpdatingCoverUrlClearsUploadedCoverKey(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $postId = $this->createPost();

        $tmpFile = $this->createTempImage('image/jpeg');
        $uploadedFile = new UploadedFile($tmpFile, 'cover.jpg', 'image/jpeg', null, true);
        $this->client->request('POST', sprintf('/api/v1/admin/posts/%s/cover-image', $postId), [], ['file' => $uploadedFile]);
        self::assertResponseIsSuccessful();
        unlink($tmpFile);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/posts/%s', $postId), [
            'title' => 'Test Article',
            'type' => 'news',
            'excerpt' => 'Un article de test.',
            'body' => ['Premier paragraphe.'],
            'readingTime' => '2 min',
            'coverImageMode' => 'url',
            'coverImageUrl' => 'https://cdn.example.test/posts/cover.webp',
        ]);

        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/posts/%s', $postId));
        self::assertResponseIsSuccessful();
        $data = $this->responseData($this->decodedJsonResponse());
        self::assertSame('https://cdn.example.test/posts/cover.webp', $data['coverImageUrl']);
        self::assertNull($data['coverImageKey']);

        $post = $this->entityManager->find(Post::class, $postId);
        self::assertInstanceOf(Post::class, $post);
        $this->entityManager->refresh($post);
        self::assertNull($post->getCoverImageKey());
    }

    public function testUpdatingPostWithoutCoverModeKeepsUploadedCoverKey(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $postId = $this->createPost();

        $tmpFile = $this->createTempImage('image/jpeg');
        $uploadedFile = new UploadedFile($tmpFile, 'cover.jpg', 'image/jpeg', null, true);
        $this->client->request('POST', sprintf('/api/v1/admin/posts/%s/cover-image', $postId), [], ['file' => $uploadedFile]);
        self::assertResponseIsSuccessful();
        unlink($tmpFile);

        $expectedKey = sprintf('posts/%s/cover.jpg', $postId);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/posts/%s', $postId), [
            'title' => 'Test Article Renamed',
            'type' => 'news',
            'excerpt' => 'Un article de test.',
            'body' => ['Premier paragraphe.'],
            'readingTime' => '2 min',
            'coverImageUrl' => null,
        ]);

        self::assertResponseIsSuccessful();

        $data = $this->responseData($this->decodedJsonResponse());
        self::assertSame($expectedKey, $data['coverImageKey']);
        self::assertIsString($data['coverImageUrl']);
        self::assertStringContainsString($expectedKey, $data['coverImageUrl']);
    }

    public function testUploadRequiresAdmin(): void
    {
        $this->client->request('POST', '/api/v1/admin/posts/nonexistent/cover-image');
        self::assertResponseStatusCodeSame(401);

        $user = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($user);
        $this->client->request('POST', '/api/v1/admin/posts/nonexistent/cover-image');
        self::assertResponseStatusCodeSame(403);
    }

    private function createPost(): string
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/posts', [
            'title' => 'Test Article',
            'slug' => 'test-article',
            'type' => 'news',
            'excerpt' => 'Un article de test.',
            'body' => ['Premier paragraphe.'],
            'readingTime' => '2 min',
        ]);
        self::assertResponseStatusCodeSame(201);
        $body = $this->decodedJsonResponse();
        $id = $this->responseData($body)['id'];
        self::assertIsString($id);

        return $id;
    }

    private function createTempImage(string $mime, int $extraBytes = 0): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'test_cover_');
        $magic = match ($mime) {
            'image/jpeg' => "\xff\xd8\xff\xe0",
            'image/png' => "\x89PNG\r\n\x1a\n",
            default => "\x00\x00",
        };
        file_put_contents($tmpFile, $magic.str_repeat("\x00", max(4, $extraBytes)));

        return $tmpFile;
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodedJsonResponse(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        $result = [];
        foreach ($decoded as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $body
     *
     * @return array<string, mixed>
     */
    private function responseData(array $body): array
    {
        self::assertArrayHasKey('data', $body);
        self::assertIsArray($body['data']);

        $result = [];
        foreach ($body['data'] as $key => $value) {
            self::assertIsString($key);
            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function errorCode(array $body): string
    {
        self::assertArrayHasKey('error', $body);
        self::assertIsArray($body['error']);
        self::assertArrayHasKey('code', $body['error']);
        self::assertIsString($body['error']['code']);

        return $body['error']['code'];
    }
}
