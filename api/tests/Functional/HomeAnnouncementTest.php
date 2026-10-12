<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Shared\Infrastructure\Double\NullMinioStorage;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Story 10.14: the announcement opened as a modal on the home page, and its admin. */
final class HomeAnnouncementTest extends FunctionalTestCase
{
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

    public function testVisitorsReadTheRunningAnnouncementAndNothingElse(): void
    {
        $this->assertNoAnnouncementForVisitors();

        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $id = $this->createAnnouncement('Inscriptions ArchiLAN 4', '-1 hour', '+3 days');
        $this->createAnnouncement('Plus tard', '+10 days', '+12 days');

        $this->client->request('GET', '/api/v1/home-announcement');
        self::assertResponseIsSuccessful();
        $announcement = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($announcement);
        self::assertSame($id, $announcement['id']);
        self::assertSame('Inscriptions ArchiLAN 4', $announcement['title']);
        self::assertSame("S'inscrire", $announcement['ctaLabel']);
        self::assertSame('/evenements', $announcement['ctaUrl']);
        self::assertNull($announcement['imageUrl']);
    }

    public function testVisitorsLearnOfAnAnnouncementADayBeforeItStarts(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $this->createAnnouncement('Dans deux jours', '+2 days', '+5 days');
        $this->assertNoAnnouncementForVisitors();

        $id = $this->createAnnouncement('Ce soir', '+6 hours', '+1 day');
        $this->client->request('GET', '/api/v1/home-announcement');
        $announcement = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($announcement);
        self::assertSame($id, $announcement['id']);
    }

    public function testOverlappingWindowsAndInvalidLinksAreRefused(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $id = $this->createAnnouncement('Inscriptions', '+1 day', '+10 days');

        $this->client->jsonRequest('POST', '/api/v1/admin/announcements', $this->payload('Autre', '+5 days', '+20 days'));
        self::assertResponseStatusCodeSame(422);
        self::assertSame('home_announcement_overlap', $this->errorCode($this->decodedJsonResponse()));

        $this->client->jsonRequest('POST', '/api/v1/admin/announcements', [...$this->payload('Autre', '+11 days', '+20 days'), 'ctaUrl' => 'javascript:alert(1)']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('home_announcement_cta_url_invalid', $this->errorCode($this->decodedJsonResponse()));

        // Its own window does not count against an edit.
        $this->client->jsonRequest('PUT', sprintf('/api/v1/admin/announcements/%s', $id), $this->payload('Inscriptions ouvertes', '+2 days', '+11 days'));
        self::assertResponseStatusCodeSame(204);
    }

    public function testTheAdminListsEditsAndDeletesAnnouncements(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $running = $this->createAnnouncement('En cours', '-1 hour', '+1 day');
        $upcoming = $this->createAnnouncement('A venir', '+3 days', '+4 days');

        $this->client->jsonRequest('PUT', sprintf('/api/v1/admin/announcements/%s', $upcoming), [...$this->payload('A venir bientot', '+3 days', '+4 days'), 'body' => 'Du texte']);
        self::assertResponseStatusCodeSame(204);

        $this->client->request('GET', '/api/v1/admin/announcements');
        self::assertResponseIsSuccessful();
        $list = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($list);
        self::assertCount(2, $list);
        $statuses = [];
        foreach ($list as $item) {
            self::assertIsArray($item);
            self::assertIsString($item['id']);
            $statuses[$item['id']] = $item['status'];
            if ($item['id'] === $upcoming) {
                self::assertSame('A venir bientot', $item['title']);
                self::assertSame('Du texte', $item['body']);
            }
        }
        self::assertSame([$upcoming => 'upcoming', $running => 'running'], $statuses);

        $this->client->jsonRequest('DELETE', sprintf('/api/v1/admin/announcements/%s', $running));
        self::assertResponseStatusCodeSame(204);
        $this->assertNoAnnouncementForVisitors();

        $this->client->jsonRequest('DELETE', sprintf('/api/v1/admin/announcements/%s', $running));
        self::assertResponseStatusCodeSame(404);
    }

    public function testAnImageIsUploadedThenRemoved(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $id = $this->createAnnouncement('Inscriptions', '-1 hour', '+1 day');

        $tmpFile = (string) tempnam(sys_get_temp_dir(), 'test_announcement_');
        file_put_contents($tmpFile, "\xff\xd8\xff\xe0".str_repeat("\x00", 8));
        $this->client->request('POST', sprintf('/api/v1/admin/announcements/%s/image', $id), [], ['file' => new UploadedFile($tmpFile, 'a.jpg', 'image/jpeg', null, true)]);
        self::assertResponseStatusCodeSame(204);
        unlink($tmpFile);

        $this->client->request('GET', '/api/v1/home-announcement');
        $announcement = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($announcement);
        self::assertIsString($announcement['imageUrl']);
        self::assertStringContainsString(sprintf('home-announcements/%s/image-', $id), $announcement['imageUrl']);

        $this->client->request('DELETE', sprintf('/api/v1/admin/announcements/%s/image', $id));
        self::assertResponseStatusCodeSame(204);
        $this->client->request('GET', '/api/v1/home-announcement');
        $announcement = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($announcement);
        self::assertNull($announcement['imageUrl']);
    }

    public function testOnlyAdminsManageAnnouncements(): void
    {
        $this->loginAs($this->createUser('member@example.org', ['ROLE_USER']));

        $this->client->request('GET', '/api/v1/admin/announcements');
        self::assertResponseStatusCodeSame(403);
        $this->client->jsonRequest('POST', '/api/v1/admin/announcements', $this->payload('Pirate', '-1 hour', '+1 day'));
        self::assertResponseStatusCodeSame(403);
    }

    private function assertNoAnnouncementForVisitors(): void
    {
        $this->client->request('GET', '/api/v1/home-announcement');
        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        self::assertArrayHasKey('data', $response);
        self::assertNull($response['data']);
    }

    private function createAnnouncement(string $title, string $startsIn, string $endsIn): string
    {
        $this->client->jsonRequest('POST', '/api/v1/admin/announcements', $this->payload($title, $startsIn, $endsIn));
        self::assertResponseStatusCodeSame(201);
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        self::assertIsString($data['id']);

        return $data['id'];
    }

    /** @return array<string, string> */
    private function payload(string $title, string $startsIn, string $endsIn): array
    {
        return [
            'title' => $title,
            'body' => '',
            'ctaLabel' => "S'inscrire",
            'ctaUrl' => '/evenements',
            'startsAt' => new \DateTimeImmutable($startsIn)->format(\DATE_ATOM),
            'endsAt' => new \DateTimeImmutable($endsIn)->format(\DATE_ATOM),
        ];
    }

    /** @param array<mixed> $body */
    private function errorCode(array $body): string
    {
        self::assertIsArray($body['error'] ?? null);
        self::assertIsString($body['error']['code'] ?? null);

        return $body['error']['code'];
    }
}
