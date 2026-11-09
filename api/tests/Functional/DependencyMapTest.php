<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\Registrations\Domain\Entity\RegistrationPresenceWindow;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Shared\Infrastructure\Double\NullMinioStorage;

/** Story 52.7: the dependency map of an event's generations, for the staff. */
final class DependencyMapTest extends FunctionalTestCase
{
    private User $admin;
    private string $eventId;
    private string $aliceRegistration;
    private string $bobRegistration;

    protected function setUp(): void
    {
        parent::setUp();
        $this->client->disableReboot();
        $this->admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Jean');
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob');
        $this->eventId = $this->createEvent('ArchiLAN 4', new \DateTimeImmutable('2026-11-14T09:00:00+01:00'), new \DateTimeImmutable('2026-11-15T20:00:00+01:00'))->getId();
        $this->aliceRegistration = $this->createRegistration($this->eventId, $alice->getId())->getId();
        $this->bobRegistration = $this->createRegistration($this->eventId, $bob->getId())->getId();
        // The map reads the confirmed registrants, as the presence timeline does.
        $this->entityManager->getConnection()->executeStatement('UPDATE registration SET submitted_at = NOW() WHERE event_id = :event', ['event' => $this->eventId]);
        NullMinioStorage::reset();
    }

    protected function tearDown(): void
    {
        NullMinioStorage::reset();
        parent::tearDown();
    }

    public function testOnlyTheStaffReadsIt(): void
    {
        $this->loginAs($this->createUser('member@example.org', ['ROLE_USER'], 'Zoé'));
        $this->client->request('GET', $this->url());

        self::assertResponseStatusCodeSame(403);
    }

    public function testEachGenerationIsScoredOnTheItemsWaitingForSomeoneAbsent(): void
    {
        // Bob only comes on Sunday.
        $this->entityManager->persist(RegistrationPresenceWindow::create($this->bobRegistration, new \DateTimeImmutable('2026-11-15T09:00:00+01:00'), new \DateTimeImmutable('2026-11-15T20:00:00+01:00')));
        $risky = $this->generation("1: {\n  Chest (Bob): Hookshot (Alice)\n  Shop (Bob): Bow (Alice)\n  House (Alice): Lamp (Bob)\n}");
        $calm = $this->generation("1: {\n  Chest (Alice): Hookshot (Alice)\n  House (Alice): Lamp (Bob)\n}");
        $unread = $this->generation(null);
        $this->entityManager->persist(Session::create('not-generated', $this->eventId, new \DateTimeImmutable()));
        $this->entityManager->flush();

        $this->loginAs($this->admin);
        $this->client->request('GET', $this->url());

        self::assertResponseStatusCodeSame(200);
        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertTrue($data['declared']);
        self::assertSame(3, $data['sphereLimit']);
        self::assertIsArray($data['generations']);
        $bySession = [];
        foreach ($data['generations'] as $generation) {
            self::assertIsArray($generation);
            self::assertIsString($generation['sessionId']);
            $bySession[$generation['sessionId']] = $generation;
        }
        $sessions = array_keys($bySession);
        sort($sessions);
        $expected = [$risky, $calm, $unread];
        sort($expected);
        self::assertSame($expected, $sessions, 'generated sessions only');

        self::assertSame(2, $bySession[$risky]['score']);
        self::assertSame([['waiting' => 'Alice', 'holding' => 'Bob', 'items' => ['Hookshot', 'Bow'], 'halfDays' => ['sam. matin', 'sam. après-midi']]], $bySession[$risky]['pairs']);
        self::assertSame(['read', 0, []], [$bySession[$calm]['playthrough'], $bySession[$calm]['score'], $bySession[$calm]['pairs']]);
        self::assertSame('missing', $bySession[$unread]['playthrough']);
    }

    public function testWithoutDeclaredWindowsNothingIsAtRisk(): void
    {
        $session = $this->generation("1: {\n  Chest (Bob): Hookshot (Alice)\n}");
        $this->entityManager->flush();

        $this->loginAs($this->admin);
        $this->client->request('GET', $this->url());

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['declared' => false, 'sphereLimit' => 3, 'generations' => [['sessionId' => $session, 'status' => Session::STATUS_DRAFT, 'playthrough' => 'skipped', 'score' => 0, 'pairs' => []]]], $this->decodedJsonResponse()['data']);
    }

    private function generation(?string $playthrough): string
    {
        $sessionId = bin2hex(random_bytes(16));
        $session = Session::create($sessionId, $this->eventId, new \DateTimeImmutable());
        $session->markGenerated($sessionId.'/output/archive.zip');
        $this->entityManager->persist($session);
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $sessionId, $this->aliceRegistration, 'game-1', 'Alice', 1, 'slot-alice'));
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $sessionId, $this->bobRegistration, 'game-1', 'Bob', 2, 'slot-bob'));
        if (null !== $playthrough) {
            $this->uploadArchive($sessionId.'/output/archive.zip', "Playthrough:\n\n0: {\n}\n".$playthrough."\n\nPaths:\n");
        }

        return $sessionId;
    }

    private function uploadArchive(string $key, string $spoiler): void
    {
        $path = tempnam(sys_get_temp_dir(), 'archive');
        self::assertIsString($path);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
        $zip->addFromString('AP_123.archipelago', 'multidata');
        $zip->addFromString('AP_123_Spoiler.txt', $spoiler);
        $zip->close();
        $contents = file_get_contents($path);
        unlink($path);
        self::assertIsString($contents);
        new NullMinioStorage()->upload('sessions', $key, $contents);
    }

    private function url(): string
    {
        return '/api/v1/admin/events/'.$this->eventId.'/dependency-map';
    }
}
