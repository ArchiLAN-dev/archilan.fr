<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Content\Domain\Post;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class PublicPostCatalogTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $entityManager;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        self::assertInstanceOf(EntityManagerInterface::class, $entityManager);
        $this->entityManager = $entityManager;

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = [$this->entityManager->getClassMetadata(Post::class)];
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testPublishedPostPayloadIncludesCoverImageUrl(): void
    {
        $post = $this->createPost('spring-sync-recap', 'https://cdn.archilan.fr/posts/spring-sync.webp');

        $this->client->jsonRequest('GET', '/api/v1/posts');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertCount(1, $data);
        $firstPost = $data[0];
        self::assertIsArray($firstPost);
        self::assertSame('https://cdn.archilan.fr/posts/spring-sync.webp', $firstPost['coverImageUrl']);

        $this->client->jsonRequest('GET', sprintf('/api/v1/posts/%s', $post->getSlug()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame('https://cdn.archilan.fr/posts/spring-sync.webp', $response['data']['coverImageUrl']);
    }

    public function testPublishedPostPayloadSerializesNullCoverImageUrl(): void
    {
        $post = $this->createPost('text-only-news', null);

        $this->client->jsonRequest('GET', sprintf('/api/v1/posts/%s', $post->getSlug()));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertNull($response['data']['coverImageUrl']);
    }

    private function createPost(string $slug, ?string $coverImageUrl): Post
    {
        $now = new \DateTimeImmutable('2026-05-03T10:00:00+00:00');
        $post = Post::draft(
            $slug,
            'Spring Sync Recap',
            Post::TYPE_RECAP,
            'Retour sur la session.',
            ['Premier paragraphe.', 'Second paragraphe.'],
            '3 min',
            null,
            null,
            $now,
            $coverImageUrl,
        );
        $post->publish($now);

        $this->entityManager->persist($post);
        $this->entityManager->flush();

        return $post;
    }

    /**
     * @return array<mixed>
     */
    private function decodedJsonResponse(): array
    {
        $decoded = json_decode($this->client->getResponse()->getContent() ?: '', true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
