<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\Sessions\Domain\Entity\Session;
use App\Shared\Infrastructure\Double\NullMinioStorage;

/**
 * Story 17.37: the admin downloads of a session read durable storage - the save the orchestrateur copies at each
 * stop, the spoiler out of the generated output archive.
 */
final class AdminSessionArchiveDownloadTest extends FunctionalTestCase
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

    public function testSaveIsServedFromStorage(): void
    {
        $session = $this->createGeneratedSession('dl-save-1', 'dl-save-1/output/archive.zip');
        new NullMinioStorage()->upload('sessions', 'dl-save-1/save/latest.apsave', 'progress');
        $this->loginAs($this->createAdmin());

        $this->client->request('GET', sprintf('/api/v1/admin/sessions/%s/download/save', $session->getId()));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('progress', $this->client->getResponse()->getContent());
        self::assertStringContainsString('dl-save-1.apsave', (string) $this->client->getResponse()->headers->get('Content-Disposition'));
    }

    public function testSpoilerIsReadOutOfTheOutputArchive(): void
    {
        $session = $this->createGeneratedSession('dl-spoil-1', 'dl-spoil-1/output/archive.zip');
        new NullMinioStorage()->upload('sessions', 'dl-spoil-1/output/archive.zip', self::zip([
            'AP_1.archipelago' => 'multidata',
            'AP_1_Spoiler.txt' => 'the spoiler',
        ]));
        $this->loginAs($this->createAdmin());

        $this->client->request('GET', sprintf('/api/v1/admin/sessions/%s/download/spoiler', $session->getId()));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('the spoiler', $this->client->getResponse()->getContent());
    }

    public function testMissingFilesAndSessionsAre404(): void
    {
        $session = $this->createGeneratedSession('dl-none-1', 'dl-none-1/output/archive.zip');
        $this->loginAs($this->createAdmin());

        $this->client->request('GET', sprintf('/api/v1/admin/sessions/%s/download/save', $session->getId()));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', sprintf('/api/v1/admin/sessions/%s/download/spoiler', $session->getId()));
        self::assertResponseStatusCodeSame(404);
        $this->client->request('GET', '/api/v1/admin/sessions/unknown/download/save');
        self::assertResponseStatusCodeSame(404);
    }

    public function testDownloadsAreAdminOnly(): void
    {
        $session = $this->createGeneratedSession('dl-auth-1', 'dl-auth-1/output/archive.zip');
        $this->loginAs($this->createUser('player@example.org', ['ROLE_USER'], 'Player'));

        $this->client->request('GET', sprintf('/api/v1/admin/sessions/%s/download/save', $session->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    private function createGeneratedSession(string $id, string $outputKey): Session
    {
        $now = new \DateTimeImmutable();
        $session = Session::create($id, 'evt-'.$id, $now);
        $session->transition(Session::STATUS_VALIDATING, $now);
        $session->transition(Session::STATUS_READY, $now);
        $session->transition(Session::STATUS_GENERATING, $now);
        $session->transition(Session::STATUS_GENERATED, $now);
        $session->markGenerated($outputKey);
        $this->entityManager->persist($session);
        $this->entityManager->flush();

        return $session;
    }

    private function createAdmin(): User
    {
        return $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
    }

    /** @param array<string, string> $files */
    private static function zip(array $files): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'zip');
        self::assertIsString($tmp);
        $zip = new \ZipArchive();
        $zip->open($tmp, \ZipArchive::OVERWRITE);
        foreach ($files as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        $data = file_get_contents($tmp);
        @unlink($tmp);
        self::assertIsString($data);

        return $data;
    }
}
