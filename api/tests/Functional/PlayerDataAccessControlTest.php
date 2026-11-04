<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\Shared\Infrastructure\Double\NullMinioStorage;
use App\WeeklyRuns\Domain\Entity\WeeklyEntry;
use App\WeeklyRuns\Domain\Entity\WeeklyRun;
use App\WeeklyRuns\Domain\Entity\WeeklyTemplate;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Story 50.3: who may read what belongs to a player. Each refusal is set against the access that succeeds, so a test
 * cannot pass only because there was nothing to read.
 */
final class PlayerDataAccessControlTest extends FunctionalTestCase
{
    private const string ALICE_PATCH = 'AP_123_P1_alice_LM.aplm';
    private const string BOB_PATCH = 'AP_123_P2_bob_LM.aplm';
    private const string ARCHIVE_KEY = 'sess-access/output/archive.zip';

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

    // ─── spoiler of a private run ──────────────────────────────────────────────

    public function testTheSpoilerIsForTheOwnerAndAdminsOnly(): void
    {
        ['run' => $run, 'owner' => $owner, 'participant' => $participant] = $this->generatedPrivateRun();
        $url = sprintf('/api/v1/runs/%s/spoiler', $run->getId());

        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(401);

        $this->loginAs($participant);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);

        $this->loginAs($this->createUser('stranger@example.org'));
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(403);

        $this->loginAs($owner);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);

        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);
    }

    // ─── patches of a private run ──────────────────────────────────────────────

    public function testAPrivateRunPlayerOnlyGetsTheirOwnPatch(): void
    {
        ['run' => $run, 'owner' => $owner, 'participant' => $participant] = $this->generatedPrivateRun();

        $this->loginAs($owner);
        $this->client->request('GET', sprintf('/api/v1/runs/%s/patches/%s', $run->getId(), self::ALICE_PATCH));
        self::assertResponseStatusCodeSame(200);

        $this->loginAs($participant);
        $this->client->request('GET', sprintf('/api/v1/runs/%s/patches/%s', $run->getId(), self::ALICE_PATCH));
        self::assertResponseStatusCodeSame(403);
    }

    public function testAStrangerSeesNoPatchOfAPrivateRun(): void
    {
        ['run' => $run] = $this->generatedPrivateRun();
        $this->loginAs($this->createUser('stranger@example.org'));

        $this->client->request('GET', sprintf('/api/v1/runs/%s/patches', $run->getId()));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['data' => ['files' => []]], $this->decodedJsonResponse());

        $this->client->request('GET', sprintf('/api/v1/runs/%s/patches/%s', $run->getId(), self::ALICE_PATCH));
        self::assertResponseStatusCodeSame(404);
    }

    public function testNeitherTheMultidataNorTheSpoilerLeaveThroughThePatchRoute(): void
    {
        ['run' => $run, 'owner' => $owner] = $this->generatedPrivateRun();
        $this->loginAs($owner);

        foreach (['AP_123.archipelago', 'AP_123_Spoiler.txt'] as $file) {
            $this->client->request('GET', sprintf('/api/v1/runs/%s/patches/%s', $run->getId(), $file));
            self::assertResponseStatusCodeSame(403, $file);
        }
    }

    // ─── weekly runs ───────────────────────────────────────────────────────────

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function weeklySlotRoutes(): iterable
    {
        yield 'reachable' => ['GET', '/slots/1/reachable'];
        yield 'reachable-token' => ['GET', '/slots/1/reachable-token'];
        yield 'hints' => ['GET', '/slots/1/hints'];
        yield 'hints-token' => ['GET', '/slots/1/hints-token'];
        yield 'hint request' => ['POST', '/slots/1/hints/request'];
        yield 'hint request by item' => ['POST', '/slots/1/hints/request-item'];
        yield 'hint status' => ['PATCH', '/slots/1/hints/12'];
        yield 'item-locations' => ['GET', '/slots/1/item-locations'];
        yield 'players' => ['GET', '/players'];
        yield 'players-token' => ['GET', '/players-token'];
    }

    #[DataProvider('weeklySlotRoutes')]
    public function testAWeeklySlotStaysWithItsPlayer(string $method, string $path): void
    {
        ['run' => $run, 'entry' => $entry] = $this->launchedWeeklyEntry();
        $bridge = self::getContainer()->get(MockHttpClient::class);
        self::assertInstanceOf(MockHttpClient::class, $bridge);
        $url = sprintf('/api/v1/weekly-runs/%s/entries/%s%s', $run->getId(), $entry->getId(), $path);

        $this->client->jsonRequest($method, $url, 'GET' === $method ? [] : ['location_id' => 12]);
        self::assertResponseStatusCodeSame(401);

        $this->loginAs($this->createUser('stranger@example.org'));
        $this->client->jsonRequest($method, $url, 'GET' === $method ? [] : ['location_id' => 12]);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(0, $bridge->getRequestsCount(), 'a refused caller reaches nothing behind the API');
    }

    public function testAWeeklyEntryIsNotReachableThroughAnotherRun(): void
    {
        ['entry' => $entry, 'player' => $player] = $this->launchedWeeklyEntry();
        $this->loginAs($player);

        $this->client->jsonRequest('GET', sprintf('/api/v1/weekly-runs/another-run/entries/%s/slots/1/reachable', $entry->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAWeeklyPlayerReadsTheirOwnSlot(): void
    {
        ['run' => $run, 'entry' => $entry, 'player' => $player] = $this->launchedWeeklyEntry();
        $bridge = self::getContainer()->get(MockHttpClient::class);
        self::assertInstanceOf(MockHttpClient::class, $bridge);
        $bridge->setResponseFactory(new MockResponse('{"counts":{"reachable_now":1}}', ['http_code' => 200]));
        $this->loginAs($player);

        $this->client->jsonRequest('GET', sprintf('/api/v1/weekly-runs/%s/entries/%s/slots/1/reachable', $run->getId(), $entry->getId()));

        self::assertResponseStatusCodeSame(200);
    }

    public function testAStrangerSeesNoPatchOfAWeeklyEntry(): void
    {
        ['run' => $run, 'entry' => $entry, 'player' => $player] = $this->launchedWeeklyEntry();
        $url = sprintf('/api/v1/weekly-runs/%s/entries/%s/patches', $run->getId(), $entry->getId());

        $this->loginAs($player);
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);
        $files = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($files);
        self::assertNotSame([], $files['files'] ?? []);

        $this->loginAs($this->createUser('stranger@example.org'));
        $this->client->request('GET', $url);
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['data' => ['files' => []]], $this->decodedJsonResponse());

        $this->client->request('GET', $url.'/'.self::ALICE_PATCH);
        self::assertResponseStatusCodeSame(404);
    }

    // ─── results of an event ───────────────────────────────────────────────────

    public function testTheResultsFollowTheVisibilityOfTheEvent(): void
    {
        $startsAt = new \DateTimeImmutable('2026-06-01T10:00:00+00:00');
        $endsAt = new \DateTimeImmutable('2026-06-02T10:00:00+00:00');

        $draft = $this->createEvent('Brouillon', $startsAt, $endsAt);
        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/session/results', $draft->getId()));
        self::assertResponseStatusCodeSame(404);

        $published = $this->createEvent('Publié', $startsAt, $endsAt, published: true);
        $this->client->jsonRequest('GET', sprintf('/api/v1/events/%s/session/results', $published->getId()));
        self::assertResponseStatusCodeSame(200);
    }

    /** Story 50.5: a private event stays listed, but its results follow the audience of its recaps (story 4.23). */
    public function testTheResultsOfAPrivateEventAreForItsParticipantsAndAdmins(): void
    {
        $now = new \DateTimeImmutable('2026-06-01T10:00:00+00:00');
        $event = $this->createEvent('Privé', $now, $now->modify('+1 day'), published: true, isPublic: false);
        $player = $this->createUser('player@example.org');
        $this->createRegistration($event->getId(), $player->getId())->confirm($now);
        $this->entityManager->flush();
        $url = sprintf('/api/v1/events/%s/session/results', $event->getId());

        $this->client->jsonRequest('GET', $url);
        self::assertResponseStatusCodeSame(404);

        $this->loginAs($this->createUser('stranger@example.org'));
        $this->client->jsonRequest('GET', $url);
        self::assertResponseStatusCodeSame(404);

        $this->loginAs($player);
        $this->client->jsonRequest('GET', $url);
        self::assertResponseStatusCodeSame(200);

        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $this->client->jsonRequest('GET', $url);
        self::assertResponseStatusCodeSame(200);
    }

    // ─── fixtures ──────────────────────────────────────────────────────────────

    /**
     * Alice owns the run and plays alice_LM, Bob joined it and plays bob_LM.
     *
     * @return array{run: Run, owner: User, participant: User}
     */
    private function generatedPrivateRun(): array
    {
        $owner = $this->createUser('alice@example.org', displayName: 'Alice');
        $participant = $this->createUser('bob@example.org', displayName: 'Bob');
        $now = new \DateTimeImmutable('2026-10-24T10:00:00+00:00');

        $run = Run::create($owner->getId(), 'Run de test', $now);
        $this->entityManager->persist($run);
        $this->entityManager->persist(RunParticipant::create($run->getId(), $participant->getId(), $now));

        $session = Session::create('sess-access', $run->getId(), $now);
        foreach ([Session::STATUS_VALIDATING, Session::STATUS_READY, Session::STATUS_GENERATING, Session::STATUS_GENERATED] as $step) {
            $session->transition($step, $now);
        }
        $session->markGenerated(self::ARCHIVE_KEY);
        $this->entityManager->persist($session);
        $run->attachSession($session->getId());
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $owner->getId(), 'game-lm', 'alice_LM', 1));
        $this->entityManager->persist(SessionSlot::create(bin2hex(random_bytes(16)), $session->getId(), $participant->getId(), 'game-lm', 'bob_LM', 2));
        $this->entityManager->flush();
        $this->uploadArchive(self::ARCHIVE_KEY);

        return ['run' => $run, 'owner' => $owner, 'participant' => $participant];
    }

    /**
     * @return array{run: WeeklyRun, entry: WeeklyEntry, player: User}
     */
    private function launchedWeeklyEntry(): array
    {
        $player = $this->createUser('weekly@example.org', displayName: 'Weekly');
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $now = new \DateTimeImmutable('2026-10-24T10:00:00+00:00');
        $template = new WeeklyTemplate(
            id: bin2hex(random_bytes(8)),
            gameId: $game->getId(),
            yamlConfig: "name: ArchiLAN\ngame: Hollow Knight\n",
            name: 'Hebdo HK',
            maxAttempts: null,
            isActive: true,
            createdAt: $now,
            updatedAt: $now,
        );
        $this->entityManager->persist($template);
        $run = new WeeklyRun(
            id: bin2hex(random_bytes(8)),
            templateId: $template->getId(),
            weekYear: 2026,
            weekNumber: 43,
            seed: 'archilan-weekly-2026-43',
            status: WeeklyRun::STATUS_ACTIVE,
            startedAt: $now,
            createdAt: $now,
        );
        $run->markGenerated(self::ARCHIVE_KEY);
        $this->entityManager->persist($run);
        $entry = new WeeklyEntry(bin2hex(random_bytes(8)), $run->getId(), $player->getId(), 1, $now, $now);
        $entry->launch('weekly-session', $now, ['host' => 'runs.local', 'port' => 38281, 'password' => null], 42001);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
        $this->uploadArchive(self::ARCHIVE_KEY);

        return ['run' => $run, 'entry' => $entry, 'player' => $player];
    }

    private function uploadArchive(string $key): void
    {
        $path = tempnam(sys_get_temp_dir(), 'patch');
        self::assertIsString($path);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($path, \ZipArchive::OVERWRITE));
        $zip->addFromString(self::ALICE_PATCH, 'patch-alice');
        $zip->addFromString(self::BOB_PATCH, 'patch-bob');
        $zip->addFromString('AP_123.archipelago', 'multidata');
        $zip->addFromString('AP_123_Spoiler.txt', 'spoiler');
        $zip->close();

        $contents = file_get_contents($path);
        unlink($path);
        self::assertIsString($contents);
        new NullMinioStorage()->upload('sessions', $key, $contents);
    }
}
