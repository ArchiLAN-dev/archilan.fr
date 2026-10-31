<?php

declare(strict_types=1);

namespace App\Tests\Functional;

/** Story 3.15: the admin picks the event featured on the home page, one at a time. */
final class EventFeaturedOnHomeTest extends FunctionalTestCase
{
    public function testFeaturingAnEventRemovesThePreviousPick(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $first = $this->createEvent('ArchiLAN #4', new \DateTimeImmutable('2026-11-13 18:00'), new \DateTimeImmutable('2026-11-15 18:00'), published: true);
        $second = $this->createEvent('ArchiLAN #5', new \DateTimeImmutable('2027-03-13 18:00'), new \DateTimeImmutable('2027-03-15 18:00'), published: true);

        self::assertEquals([$first->getId() => false, $second->getId() => false], $this->featuredByEvent('/api/v1/admin/events'));

        $this->client->jsonRequest('PUT', sprintf('/api/v1/admin/events/%s/featured', $first->getId()), ['featured' => true]);
        self::assertResponseStatusCodeSame(200);
        self::assertEquals([$first->getId() => true, $second->getId() => false], $this->featuredByEvent('/api/v1/admin/events'));

        $this->client->jsonRequest('PUT', sprintf('/api/v1/admin/events/%s/featured', $second->getId()), ['featured' => true]);
        self::assertResponseStatusCodeSame(200);
        self::assertEquals([$first->getId() => false, $second->getId() => true], $this->featuredByEvent('/api/v1/admin/events'));

        $this->client->getCookieJar()->clear();
        self::assertEquals([$first->getId() => false, $second->getId() => true], $this->featuredByEvent('/api/v1/events'));

        $this->loginAs($this->createUser('admin2@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $this->client->jsonRequest('PUT', sprintf('/api/v1/admin/events/%s/featured', $second->getId()), ['featured' => false]);
        self::assertResponseStatusCodeSame(200);
        self::assertEquals([$first->getId() => false, $second->getId() => false], $this->featuredByEvent('/api/v1/admin/events'));
    }

    public function testADraftIsRefusedAndABadBodyIs400(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $draft = $this->createEvent('ArchiLAN #4', new \DateTimeImmutable('2026-11-13 18:00'), new \DateTimeImmutable('2026-11-15 18:00'));
        $url = sprintf('/api/v1/admin/events/%s/featured', $draft->getId());

        $this->client->jsonRequest('PUT', $url, ['featured' => true]);
        self::assertResponseStatusCodeSame(422);
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        $details = $error['details'] ?? null;
        self::assertIsArray($details);
        self::assertArrayHasKey('featured', $details);

        $this->client->jsonRequest('PUT', $url, ['featured' => 'yes']);
        self::assertResponseStatusCodeSame(400);

        $this->client->jsonRequest('PUT', '/api/v1/admin/events/unknown/featured', ['featured' => true]);
        self::assertResponseStatusCodeSame(404);
    }

    public function testOnlyAnAdminMayFeature(): void
    {
        $event = $this->createEvent('ArchiLAN #4', new \DateTimeImmutable('2026-11-13 18:00'), new \DateTimeImmutable('2026-11-15 18:00'), published: true);
        $url = sprintf('/api/v1/admin/events/%s/featured', $event->getId());

        $this->client->jsonRequest('PUT', $url, ['featured' => true]);
        self::assertResponseStatusCodeSame(401);

        $this->loginAs($this->createUser('member@example.org'));
        $this->client->jsonRequest('PUT', $url, ['featured' => true]);
        self::assertResponseStatusCodeSame(403);
    }

    /** @return array<string, bool> the `featuredOnHome` of each listed event, by id (compared without key order) */
    private function featuredByEvent(string $url): array
    {
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        $featured = [];
        foreach ($data as $event) {
            self::assertIsArray($event);
            self::assertIsString($event['id'] ?? null);
            self::assertIsBool($event['featuredOnHome'] ?? null);
            $featured[$event['id']] = $event['featuredOnHome'];
        }

        return $featured;
    }
}
