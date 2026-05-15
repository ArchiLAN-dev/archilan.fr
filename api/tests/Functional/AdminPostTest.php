<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Content\Domain\Post;
use App\Identity\Domain\User;
use Doctrine\ORM\Tools\SchemaTool;

final class AdminPostTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Post::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAnonymousRequestIsRejected(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/posts');
        self::assertResponseStatusCodeSame(401);

        $this->client->jsonRequest('POST', '/api/v1/admin/posts', $this->validCreatePayload());
        self::assertResponseStatusCodeSame(401);
    }

    public function testLambdaUserIsRejected(): void
    {
        $lambda = $this->createUser('lambda@example.org', ['ROLE_USER']);
        $this->loginAs($lambda);

        $this->client->jsonRequest('GET', '/api/v1/admin/posts');
        self::assertResponseStatusCodeSame(403);

        $this->client->jsonRequest('POST', '/api/v1/admin/posts', $this->validCreatePayload());
        self::assertResponseStatusCodeSame(403);
    }

    public function testAdminSeesEmptyPostList(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/posts');

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertSame([], $response['data']);
    }

    public function testAdminCreatesADraftPost(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/posts', $this->validCreatePayload());

        self::assertResponseStatusCodeSame(201);
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertSame('actualite-de-test', $response['data']['slug']);
        self::assertSame('Actualité de test', $response['data']['title']);
        self::assertSame(Post::TYPE_NEWS, $response['data']['type']);
        self::assertSame(Post::STATUS_DRAFT, $response['data']['status']);
        self::assertNull($response['data']['publishedAt']);
        self::assertIsString($response['data']['id']);
    }

    public function testAdminListsAllPostsIncludingDrafts(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->createPost('post-a', Post::TYPE_NEWS);
        $postBId = $this->createPost('post-b', Post::TYPE_RECAP);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/posts/%s/publish', $postBId));
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', '/api/v1/admin/posts');
        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertIsArray($response['data']);
        self::assertCount(2, $response['data']);

        $statuses = array_column($response['data'], 'status');
        self::assertContains(Post::STATUS_DRAFT, $statuses);
        self::assertContains(Post::STATUS_PUBLISHED, $statuses);
    }

    public function testAdminShowsASinglePost(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $id = $this->createPost('mon-article', Post::TYPE_NEWS);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/posts/%s', $id));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame('mon-article', $data['slug']);
    }

    public function testAdminShowReturnsNotFoundForUnknownPost(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/posts/nonexistent');
        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminUpdatesEditableFields(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $id = $this->createPost('mon-article', Post::TYPE_NEWS);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/posts/%s', $id), [
            'title' => 'Titre mis à jour',
            'type' => Post::TYPE_RECAP,
            'excerpt' => 'Extrait mis à jour.',
            'body' => ['Paragraphe 1.', 'Paragraphe 2.'],
            'readingTime' => '4 min',
            'vodUrl' => 'https://example.com/vod',
            'relatedEventSlug' => 'lan-2026',
        ]);

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame('Titre mis à jour', $data['title']);
        self::assertSame(Post::TYPE_RECAP, $data['type']);
        self::assertSame('Extrait mis à jour.', $data['excerpt']);
        self::assertSame(['Paragraphe 1.', 'Paragraphe 2.'], $data['body']);
        self::assertSame('https://example.com/vod', $data['vodUrl']);
        self::assertSame('lan-2026', $data['relatedEventSlug']);
        self::assertSame('mon-article', $data['slug'], 'slug must not change on update');
    }

    public function testAdminPublishesADraft(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $id = $this->createPost('mon-article', Post::TYPE_NEWS);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/posts/%s/publish', $id));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame(Post::STATUS_PUBLISHED, $data['status']);
        self::assertIsString($data['publishedAt']);
    }

    public function testAdminUnpublishesAPublishedPost(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);
        $id = $this->createPost('mon-article', Post::TYPE_NEWS);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/posts/%s/publish', $id));
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/posts/%s/unpublish', $id));
        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame(Post::STATUS_DRAFT, $data['status']);
    }

    public function testPublicListOnlyShowsPublishedContent(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $draftId = $this->createPost('brouillon', Post::TYPE_NEWS);
        $publishedId = $this->createPost('publie', Post::TYPE_NEWS);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/posts/%s/publish', $publishedId));
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', '/api/v1/posts');
        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertCount(1, $data);
        $firstPost = $data[0];
        self::assertIsArray($firstPost);
        self::assertSame('publie', $firstPost['slug']);

        $_ = $draftId;
    }

    public function testCreateRejectsInvalidType(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/posts', array_merge($this->validCreatePayload(), [
            'type' => 'invalid-type',
        ]));

        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        $details = $error['details'];
        self::assertIsArray($details);
        self::assertArrayHasKey('type', $details);
    }

    public function testCreateRejectsDuplicateSlug(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/posts', $this->validCreatePayload());
        self::assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('POST', '/api/v1/admin/posts', $this->validCreatePayload());
        self::assertResponseStatusCodeSame(422);
        $response = $this->decodedJsonResponse();
        $error = $response['error'];
        self::assertIsArray($error);
        $details = $error['details'];
        self::assertIsArray($details);
        self::assertArrayHasKey('slug', $details);
    }

    public function testUpdateReturnsNotFoundForUnknownPost(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', '/api/v1/admin/posts/nonexistent', ['title' => 'Titre']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testPublishReturnsNotFoundForUnknownPost(): void
    {
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/posts/nonexistent/publish');
        self::assertResponseStatusCodeSame(404);
    }

    private function createPost(string $slug, string $type): string
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/posts', [
            'slug' => $slug,
            'title' => 'Article '.$slug,
            'type' => $type,
            'excerpt' => 'Un extrait pour '.$slug.'.',
            'body' => ['Contenu de test.'],
            'readingTime' => '2 min',
        ]);
        self::assertResponseStatusCodeSame(201);
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        $id = $data['id'];
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function validCreatePayload(): array
    {
        return [
            'slug' => 'actualite-de-test',
            'title' => 'Actualité de test',
            'type' => Post::TYPE_NEWS,
            'excerpt' => 'Un court résumé de l\'article.',
            'body' => ['Premier paragraphe.', 'Deuxième paragraphe.'],
            'readingTime' => '3 min',
        ];
    }
}
