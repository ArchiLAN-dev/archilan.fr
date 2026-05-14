<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Content\Domain\Post;
use App\Identity\Application\AuthSessionSigner;
use App\Identity\Domain\User;
use App\Shared\Infrastructure\NullMinioStorage;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class AdminPostCoverImageTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;
    private AuthSessionSigner $authSessionSigner;
    private NullMinioStorage $minioStorage;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $authSessionSigner = self::getContainer()->get(AuthSessionSigner::class);
        self::assertInstanceOf(AuthSessionSigner::class, $authSessionSigner);
        $this->authSessionSigner = $authSessionSigner;

        $minioStorage = self::getContainer()->get(NullMinioStorage::class);
        self::assertInstanceOf(NullMinioStorage::class, $minioStorage);
        $this->minioStorage = $minioStorage;

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Post::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);

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

        $tmpFile = $this->createTempImage('image/jpeg', 10 * 1024 * 1024 + 100);
        $uploadedFile = new UploadedFile($tmpFile, 'cover.jpg', 'image/jpeg', null, true);

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
            'relatedEventSlug' => null,
            'vodUrl' => null,
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
            'relatedEventSlug' => null,
            'vodUrl' => null,
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

        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($lambda);
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
     * @param list<string> $roles
     */
    private function createUser(string $email, array $roles): User
    {
        $now = new \DateTimeImmutable('2026-04-25T10:00:00+00:00');
        $user = new User(
            bin2hex(random_bytes(16)),
            $email,
            mb_strtolower($email),
            null,
            'test-password-hash',
            $roles,
            $now,
            $now,
            $now,
        );
        $this->entityManager->persist($user);
        $this->entityManager->flush();

        return $user;
    }

    private function loginAs(User $user): void
    {
        $this->client->getCookieJar()->set(
            new Cookie(AuthSessionSigner::COOKIE_NAME, $this->authSessionSigner->sign($user->getId())),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function decodedJsonResponse(): array
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
