<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/** Story 45.3: the partners thanked on an event, chosen by an admin and read publicly by reference. */
final class EventPartnersTest extends FunctionalTestCase
{
    private const string FIRST = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string SECOND = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testAnAdminChoosesPartnersAndTheEventExposesThemInOrder(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $event = $this->createEvent('ArchiLAN #4', new \DateTimeImmutable('2026-11-13 18:00'), new \DateTimeImmutable('2026-11-15 18:00'), published: true);
        $url = sprintf('/api/v1/admin/events/%s/partners', $event->getId());

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);
        self::assertSame([], $this->decodedJsonResponse()['data']);

        $this->client->jsonRequest('PUT', $url, ['partners' => [
            ['partnerId' => self::SECOND, 'mention' => 'Lots offerts'],
            ['partnerId' => self::FIRST, 'mention' => ''],
        ]]);
        self::assertResponseStatusCodeSame(200);

        $expected = [
            ['partnerId' => self::SECOND, 'mention' => 'Lots offerts'],
            ['partnerId' => self::FIRST, 'mention' => null],
        ];
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);
        self::assertSame($expected, $this->decodedJsonResponse()['data']);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', sprintf('/api/v1/events/%s', $event->getId()));
        self::assertResponseStatusCodeSame(200);
        $public = $this->decodedJsonResponse()['data'];
        self::assertIsArray($public);
        self::assertSame($expected, $public['partners']);
    }

    public function testAnInvalidListIsRefusedWithAMessageOnPartners(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $event = $this->createEvent('ArchiLAN #4', new \DateTimeImmutable('2026-11-13 18:00'), new \DateTimeImmutable('2026-11-15 18:00'));
        $url = sprintf('/api/v1/admin/events/%s/partners', $event->getId());

        foreach ([
            ['partners' => 'nope'],
            ['partners' => [['mention' => 'sans id']]],
            ['partners' => [['partnerId' => self::FIRST, 'mention' => null], ['partnerId' => self::FIRST, 'mention' => null]]],
            ['partners' => [['partnerId' => self::FIRST, 'mention' => str_repeat('x', 121)]]],
        ] as $payload) {
            $this->client->jsonRequest('PUT', $url, $payload);
            self::assertResponseStatusCodeSame(422);
            $error = $this->decodedJsonResponse()['error'] ?? null;
            self::assertIsArray($error);
            $errors = $error['details'] ?? null;
            self::assertIsArray($errors);
            self::assertArrayHasKey('partners', $errors);
        }
    }

    public function testAnUnknownEventIs404(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));

        $this->client->request('GET', '/api/v1/admin/events/unknown/partners');
        self::assertResponseStatusCodeSame(404);

        $this->client->jsonRequest('PUT', '/api/v1/admin/events/unknown/partners', ['partners' => []]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testOnlyAnAdminMayReadOrChoose(): void
    {
        $event = $this->createEvent('ArchiLAN #4', new \DateTimeImmutable('2026-11-13 18:00'), new \DateTimeImmutable('2026-11-15 18:00'));
        $url = sprintf('/api/v1/admin/events/%s/partners', $event->getId());

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(401);

        $this->loginAs($this->createUser('member@example.org'));
        $this->client->jsonRequest('PUT', $url, ['partners' => []]);
        self::assertResponseStatusCodeSame(403);
    }
}
