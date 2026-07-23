<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/**
 * Admin-only free-text notes on a game (story 3.12, issue #303): saved via a dedicated admin endpoint,
 * surfaced only in the admin detail payload, never in the base list nor any public payload.
 */
final class AdminGameNotesTest extends FunctionalTestCase
{
    public function testAdminSavesAndReadsNotes(): void
    {
        $this->loginAsAdmin();
        $game = $this->createGame('Hollow Knight', 'hollow-knight');

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/games/%s/notes', $game->getId()), [
            'adminNotes' => '  Pièges de config: le mode X plante la génération.  ',
        ]);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Pièges de config: le mode X plante la génération.', $this->adminNotesFromResponse());

        // Persisted and echoed by the admin detail payload.
        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/games/%s', $game->getId()));
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Pièges de config: le mode X plante la génération.', $this->adminNotesFromResponse());
    }

    public function testBlankNotesClearToNull(): void
    {
        $this->loginAsAdmin();
        $game = $this->createGame('Hollow Knight', 'hollow-knight');

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/games/%s/notes', $game->getId()), ['adminNotes' => 'x']);
        self::assertResponseStatusCodeSame(200);

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/games/%s/notes', $game->getId()), ['adminNotes' => '   ']);
        self::assertResponseStatusCodeSame(200);
        self::assertNull($this->adminNotesFromResponse());
    }

    public function testUnknownGameReturns404(): void
    {
        $this->loginAsAdmin();

        $this->client->jsonRequest('PATCH', '/api/v1/admin/games/does-not-exist/notes', ['adminNotes' => 'x']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testNotesEndpointRequiresAdmin(): void
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/games/%s/notes', $game->getId()), ['adminNotes' => 'x']);
        self::assertResponseStatusCodeSame(401);

        $this->loginAs($this->createUser('user@example.org', ['ROLE_USER']));
        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/games/%s/notes', $game->getId()), ['adminNotes' => 'x']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testNotesAreDetailOnlyNotInAdminList(): void
    {
        $this->loginAsAdmin();
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/games/%s/notes', $game->getId()), ['adminNotes' => 'secret interne']);
        self::assertResponseStatusCodeSame(200);

        $this->client->jsonRequest('GET', '/api/v1/admin/games');
        self::assertResponseStatusCodeSame(200);
        $rows = $this->decodedJsonResponse()['data'];
        self::assertIsArray($rows);

        $found = false;
        foreach ($rows as $row) {
            self::assertIsArray($row);
            self::assertArrayNotHasKey('adminNotes', $row);
            if (($row['id'] ?? null) === $game->getId()) {
                $found = true;
            }
        }
        self::assertTrue($found, 'the created game should appear in the admin list');
    }

    private function adminNotesFromResponse(): ?string
    {
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertArrayHasKey('adminNotes', $data);
        $notes = $data['adminNotes'];
        self::assertTrue(null === $notes || is_string($notes));

        return $notes;
    }

    private function loginAsAdmin(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
    }
}
