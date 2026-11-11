<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/** Story 53.5: the help guides, and their admin. */
final class HelpGuideTest extends FunctionalTestCase
{
    public function testVisitorsReadOnlyThePublishedGuidesInOrder(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $pelles = $this->createGuide('pelles');
        $draft = $this->createGuide('brouillon', false);
        $passeur = $this->createGuide('passeur');
        $this->client->jsonRequest('POST', '/api/v1/admin/help-guides/reorder', ['ids' => [$passeur, $draft, $pelles]]);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/v1/help-guides');
        self::assertResponseIsSuccessful();
        $cards = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($cards);
        self::assertSame(['passeur', 'pelles'], array_column($cards, 'slug'));
        self::assertSame([['slug' => 'passeur', 'title' => 'Guide passeur', 'summary' => 'En deux mots.']], array_slice($cards, 0, 1));

        $this->client->request('GET', '/api/v1/help-guides/pelles');
        self::assertResponseIsSuccessful();
        $guide = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($guide);
        self::assertSame('## Le texte', $guide['body']);
        self::assertIsString($guide['updatedAt']);

        // A draft or an unknown slug is a 404 for visitors.
        $this->client->request('GET', '/api/v1/help-guides/brouillon');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('help_guide_not_found', $this->errorCode($this->decodedJsonResponse()));
        $this->client->request('GET', '/api/v1/help-guides/inconnu');
        self::assertResponseStatusCodeSame(404);

        $this->client->request('GET', '/api/v1/admin/help-guides');
        self::assertResponseIsSuccessful();
        $all = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($all);
        self::assertSame(['passeur', 'brouillon', 'pelles'], array_column($all, 'slug'));
        self::assertSame([true, false, true], array_column($all, 'published'));
    }

    public function testTheAdminEditsAndDeletesAGuide(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $id = $this->createGuide('pelles');

        $this->client->jsonRequest('PUT', sprintf('/api/v1/admin/help-guides/%s', $id), [...$this->payload('les-pelles'), 'title' => 'Les pelles']);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/v1/help-guides/les-pelles');
        self::assertResponseIsSuccessful();
        $guide = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($guide);
        self::assertSame('Les pelles', $guide['title']);
        $this->client->request('GET', '/api/v1/help-guides/pelles');
        self::assertResponseStatusCodeSame(404);

        // A published guide is unpublished before it can go.
        $this->client->request('DELETE', sprintf('/api/v1/admin/help-guides/%s', $id));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('help_guide_published', $this->errorCode($this->decodedJsonResponse()));
        $this->client->jsonRequest('PUT', sprintf('/api/v1/admin/help-guides/%s', $id), [...$this->payload('les-pelles'), 'published' => false]);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('DELETE', sprintf('/api/v1/admin/help-guides/%s', $id));
        self::assertResponseStatusCodeSame(204);
        $this->client->request('DELETE', sprintf('/api/v1/admin/help-guides/%s', $id));
        self::assertResponseStatusCodeSame(404);
    }

    public function testATakenReservedOrMalformedSlugIsRefused(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $this->createGuide('pelles');
        $other = $this->createGuide('passeur');

        $this->client->jsonRequest('POST', '/api/v1/admin/help-guides', $this->payload('pelles'));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('help_guide_slug_taken', $this->errorCode($this->decodedJsonResponse()));
        $this->client->jsonRequest('PUT', sprintf('/api/v1/admin/help-guides/%s', $other), $this->payload('pelles'));
        self::assertResponseStatusCodeSame(409);

        $this->client->jsonRequest('POST', '/api/v1/admin/help-guides', $this->payload('archipelago'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('help_guide_slug_reserved', $this->errorCode($this->decodedJsonResponse()));
        $this->client->jsonRequest('POST', '/api/v1/admin/help-guides', $this->payload('Les Pelles'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('help_guide_slug_invalid', $this->errorCode($this->decodedJsonResponse()));
    }

    public function testOnlyAdminsManageGuides(): void
    {
        $this->client->request('GET', '/api/v1/admin/help-guides');
        self::assertResponseStatusCodeSame(401);

        $this->loginAs($this->createUser('member@example.org', ['ROLE_USER']));
        $this->client->request('GET', '/api/v1/admin/help-guides');
        self::assertResponseStatusCodeSame(403);
        $this->client->jsonRequest('POST', '/api/v1/admin/help-guides', $this->payload('pirate'));
        self::assertResponseStatusCodeSame(403);
        $this->client->jsonRequest('POST', '/api/v1/admin/help-guides/reorder', ['ids' => []]);
        self::assertResponseStatusCodeSame(403);

        $this->client->request('GET', '/api/v1/help-guides');
        self::assertResponseIsSuccessful();
    }

    private function createGuide(string $slug, bool $published = true): string
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/help-guides', [...$this->payload($slug), 'published' => $published]);
        self::assertResponseStatusCodeSame(201);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsString($data['id']);

        return $data['id'];
    }

    /** @return array<string, string|bool> */
    private function payload(string $slug): array
    {
        return ['slug' => $slug, 'title' => 'Guide '.$slug, 'summary' => 'En deux mots.', 'body' => '## Le texte', 'published' => true];
    }

    /** @param array<mixed> $body */
    private function errorCode(array $body): string
    {
        self::assertIsArray($body['error'] ?? null);
        self::assertIsString($body['error']['code'] ?? null);

        return $body['error']['code'];
    }
}
