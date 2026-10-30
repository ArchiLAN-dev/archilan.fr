<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;

/** Story 46.1: admin notes on a registration, their follow-ups and their history. */
final class AdminNoteTest extends FunctionalTestCase
{
    public function testTheFullLifeOfANote(): void
    {
        $author = $this->createUser('author@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Jean');
        $editor = $this->createUser('editor@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Léa');
        $registrationId = $this->registrationId();

        $this->loginAs($author);
        $noteId = $this->write($registrationId, 'A payé 15 euros en espèces.');

        $this->loginAs($editor);
        $this->client->jsonRequest('PATCH', '/api/v1/admin/notes/'.$noteId, ['body' => 'A payé 20 euros en espèces.']);
        self::assertResponseStatusCodeSame(204);
        $this->client->jsonRequest('POST', '/api/v1/admin/notes/'.$noteId.'/follow-ups', ['body' => 'À valider dans HelloAsso.']);
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/api/v1/admin/notes/'.$noteId.'/settle', ['body' => 'Régularisé.']);
        self::assertResponseStatusCodeSame(204);
        $this->client->request('POST', '/api/v1/admin/notes/'.$noteId.'/reopen');
        self::assertResponseStatusCodeSame(204);

        $notes = $this->notes($registrationId);
        self::assertCount(1, $notes);
        $note = $notes[0];
        self::assertIsArray($note);
        self::assertSame('A payé 20 euros en espèces.', $note['body']);
        self::assertSame('open', $note['status']);
        self::assertSame(['id' => $author->getId(), 'displayName' => 'Jean'], $note['author']);
        self::assertTrue($note['edited']);
        self::assertIsArray($note['followUps']);
        self::assertSame(['comment', 'settled', 'reopened'], array_column($note['followUps'], 'kind'));
        self::assertSame(['À valider dans HelloAsso.', 'Régularisé.', ''], array_column($note['followUps'], 'body'));
        self::assertSame([['id' => $editor->getId(), 'displayName' => 'Léa']], array_values(array_unique(array_column($note['followUps'], 'author'), SORT_REGULAR)));

        $this->client->request('GET', '/api/v1/admin/notes/'.$noteId.'/revisions');
        self::assertResponseIsSuccessful();
        $revisions = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($revisions);
        self::assertCount(1, $revisions);
        self::assertIsArray($revisions[0]);
        self::assertSame('A payé 15 euros en espèces.', $revisions[0]['previousBody']);
        self::assertSame(['id' => $editor->getId(), 'displayName' => 'Léa'], $revisions[0]['editor']);
    }

    public function testOpenNotesComeFirstAndANoteWithoutEditIsNotEdited(): void
    {
        $this->loginAs($this->admin());
        $registrationId = $this->registrationId();
        $settled = $this->write($registrationId, 'Ancienne note.');
        $this->client->jsonRequest('POST', '/api/v1/admin/notes/'.$settled.'/settle', []);
        self::assertResponseStatusCodeSame(204);
        $open = $this->write($registrationId, 'Note ouverte.');
        $this->client->jsonRequest('PATCH', '/api/v1/admin/notes/'.$open, ['body' => ' Note ouverte. ']);
        self::assertResponseStatusCodeSame(204);

        $notes = $this->notes($registrationId);
        self::assertSame([$open, $settled], array_column($notes, 'id'));
        self::assertSame([false, false], array_column($notes, 'edited'));
        self::assertSame([], $this->notes($this->registrationId()));
    }

    public function testAStateAlreadyReachedIsAConflict(): void
    {
        $this->loginAs($this->admin());
        $noteId = $this->write($this->registrationId(), 'Doublon possible.');

        $this->client->jsonRequest('POST', '/api/v1/admin/notes/'.$noteId.'/reopen', []);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('note_already_open', $this->errorCode($this->decodedJsonResponse()));

        $this->client->jsonRequest('POST', '/api/v1/admin/notes/'.$noteId.'/settle', []);
        $this->client->jsonRequest('POST', '/api/v1/admin/notes/'.$noteId.'/settle', []);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('note_already_settled', $this->errorCode($this->decodedJsonResponse()));
    }

    public function testInvalidInputIsRefusedWithItsCode(): void
    {
        $this->loginAs($this->admin());
        $registrationId = $this->registrationId();

        $this->client->jsonRequest('POST', '/api/v1/admin/notes', ['targetType' => 'game', 'targetId' => $registrationId, 'body' => 'Note.']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('note_target_type_invalid', $this->errorCode($this->decodedJsonResponse()));

        $this->client->jsonRequest('POST', '/api/v1/admin/notes', ['targetType' => 'registration', 'targetId' => 'missing', 'body' => 'Note.']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('note_target_not_found', $this->errorCode($this->decodedJsonResponse()));

        $this->client->jsonRequest('POST', '/api/v1/admin/notes', ['targetType' => 'registration', 'targetId' => $registrationId, 'body' => '  ']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('note_body_invalid', $this->errorCode($this->decodedJsonResponse()));

        $this->client->jsonRequest('POST', '/api/v1/admin/notes', ['targetType' => 'registration', 'targetId' => $registrationId]);
        self::assertResponseStatusCodeSame(400);

        $noteId = $this->write($registrationId, 'Note.');
        $this->client->jsonRequest('POST', '/api/v1/admin/notes/'.$noteId.'/follow-ups', ['body' => '']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('note_follow_up_body_invalid', $this->errorCode($this->decodedJsonResponse()));

        $this->client->request('GET', '/api/v1/admin/notes?targetType=game&targetId=x');
        self::assertResponseStatusCodeSame(422);
        self::assertSame('note_target_type_invalid', $this->errorCode($this->decodedJsonResponse()));
    }

    public function testAnUnknownNoteIsNotFound(): void
    {
        $this->loginAs($this->admin());

        $this->client->jsonRequest('PATCH', '/api/v1/admin/notes/missing', ['body' => 'Note.']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('note_not_found', $this->errorCode($this->decodedJsonResponse()));
        $this->client->jsonRequest('POST', '/api/v1/admin/notes/missing/follow-ups', ['body' => 'Suivi.']);
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', '/api/v1/admin/notes/missing/settle', []);
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/v1/admin/notes/missing/revisions');
        self::assertResponseStatusCodeSame(404);
        self::assertSame('note_not_found', $this->errorCode($this->decodedJsonResponse()));
    }

    public function testOpenCountsAreReadInOneCallForAList(): void
    {
        $this->loginAs($this->admin());
        $twoOpen = $this->registrationId();
        $oneOpen = $this->registrationId();
        $settledOnly = $this->registrationId();
        $this->write($twoOpen, 'Première.');
        $this->write($twoOpen, 'Seconde.');
        $this->write($oneOpen, 'Une.');
        $settled = $this->write($oneOpen, 'Réglée.');
        $this->client->jsonRequest('POST', '/api/v1/admin/notes/'.$settled.'/settle', []);
        $other = $this->write($settledOnly, 'Réglée aussi.');
        $this->client->jsonRequest('POST', '/api/v1/admin/notes/'.$other.'/settle', []);

        $this->client->jsonRequest('POST', '/api/v1/admin/notes/open-counts', ['targetType' => 'registration', 'targetIds' => [$twoOpen, $oneOpen, $settledOnly, 'unknown']]);
        self::assertResponseIsSuccessful();
        $counts = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($counts);
        $expected = [$twoOpen => 2, $oneOpen => 1];
        ksort($counts);
        ksort($expected);
        self::assertSame($expected, $counts);

        $this->client->jsonRequest('POST', '/api/v1/admin/notes/open-counts', ['targetType' => 'registration', 'targetIds' => []]);
        self::assertResponseIsSuccessful();
        self::assertSame('{"data":{},"meta":[]}', $this->client->getResponse()->getContent());

        $this->client->jsonRequest('POST', '/api/v1/admin/notes/open-counts', ['targetType' => 'registration', 'targetIds' => array_map(static fn (int $i): string => 'r'.$i, range(1, 501))]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('note_target_ids_invalid', $this->errorCode($this->decodedJsonResponse()));

        $this->client->jsonRequest('POST', '/api/v1/admin/notes/open-counts', ['targetType' => 'registration', 'targetIds' => [1, 2]]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('note_target_ids_invalid', $this->errorCode($this->decodedJsonResponse()));
    }

    public function testEveryRouteIsForAdminsOnly(): void
    {
        $this->loginAs($this->admin());
        $noteId = $this->write($this->registrationId(), 'Note.');
        $routes = [
            ['GET', '/api/v1/admin/notes?targetType=registration&targetId=x'],
            ['POST', '/api/v1/admin/notes'],
            ['POST', '/api/v1/admin/notes/open-counts'],
            ['PATCH', '/api/v1/admin/notes/'.$noteId],
            ['POST', '/api/v1/admin/notes/'.$noteId.'/follow-ups'],
            ['POST', '/api/v1/admin/notes/'.$noteId.'/settle'],
            ['POST', '/api/v1/admin/notes/'.$noteId.'/reopen'],
            ['GET', '/api/v1/admin/notes/'.$noteId.'/revisions'],
        ];

        $this->client->getCookieJar()->clear();
        foreach ($routes as [$method, $uri]) {
            $this->client->jsonRequest($method, $uri, ['body' => 'Note.']);
            self::assertResponseStatusCodeSame(401, $method.' '.$uri);
        }

        $this->loginAs($this->createUser('member@example.org'));
        foreach ($routes as [$method, $uri]) {
            $this->client->jsonRequest($method, $uri, ['body' => 'Note.']);
            self::assertResponseStatusCodeSame(403, $method.' '.$uri);
        }
    }

    private function admin(): User
    {
        return $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
    }

    private function registrationId(): string
    {
        $event = $this->createEvent('LAN', new \DateTimeImmutable('2026-11-14T10:00:00+01:00'), new \DateTimeImmutable('2026-11-15T18:00:00+01:00'));
        $player = $this->createUser(bin2hex(random_bytes(4)).'@example.org');

        return $this->createRegistration($event->getId(), $player->getId())->getId();
    }

    private function write(string $registrationId, string $body): string
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/notes', ['targetType' => 'registration', 'targetId' => $registrationId, 'body' => $body]);
        self::assertResponseStatusCodeSame(201);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsString($data['id'] ?? null);

        return $data['id'];
    }

    /** @param array<mixed> $body */
    private function errorCode(array $body): string
    {
        self::assertIsArray($body['error'] ?? null);
        self::assertIsString($body['error']['code'] ?? null);

        return $body['error']['code'];
    }

    /** @return list<mixed> */
    private function notes(string $registrationId): array
    {
        $this->client->request('GET', '/api/v1/admin/notes?targetType=registration&targetId='.$registrationId);
        self::assertResponseIsSuccessful();
        $notes = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($notes);
        self::assertTrue(array_is_list($notes));

        return $notes;
    }
}
