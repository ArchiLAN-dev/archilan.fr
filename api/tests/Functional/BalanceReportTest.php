<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Events\Domain\Entity\Event;
use App\GameSelection\Domain\Entity\Game;
use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\Registrations\Domain\Entity\Registration;
use App\Sessions\Application\Handler\MeasureBalanceReportJobHandler;
use App\Sessions\Application\Message\MeasureBalanceReportJob;
use App\Sessions\Domain\Entity\BalanceReport;
use App\Sessions\Infrastructure\Double\NullRunnerGateway;
use App\Sessions\Infrastructure\Double\StubSeedArchiveGateway;
use App\Shared\Infrastructure\Double\NullMinioStorage;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Story 56.3: a dry generation of an event or a private run, and the balance report built from its measures.
 *
 * The orchestrator and the multidata reader are simulated: what is pinned is the path around them - the slots sent
 * on a `balance-` session, the webhook that records the output and queues the measure, the report's shares and
 * alerts, the staleness after a YAML is saved, the ROM case, and who may see what.
 */
final class BalanceReportTest extends FunctionalTestCase
{
    private const string WEBHOOK_URL = '/api/v1/internal/orchestrateur/webhook';
    private const string WEBHOOK_SECRET = 'test-orchestrateur-secret';
    private const string OUTPUT_KEY = 'balance-event/output/archive.zip';

    protected function setUp(): void
    {
        parent::setUp();
        NullRunnerGateway::reset();
        NullMinioStorage::reset();
        StubSeedArchiveGateway::reset();
    }

    protected function tearDown(): void
    {
        StubSeedArchiveGateway::reset();
        NullRunnerGateway::reset();
        parent::tearDown();
    }

    public function testAnAdminDryGeneratesAnEventAndReadsItsReport(): void
    {
        [$event, $admin, $alice, $bob] = $this->eventWithTwoPlayers();
        $this->loginAs($admin);

        $this->client->request('POST', '/api/v1/admin/events/'.$event->getId().'/balance');
        self::assertResponseStatusCodeSame(202);
        $configured = NullRunnerGateway::$lastConfigureSlots;
        self::assertIsArray($configured);
        self::assertCount(3, $configured);

        // A second ask while the first one runs is refused.
        $this->client->request('POST', '/api/v1/admin/events/'.$event->getId().'/balance');
        self::assertResponseStatusCodeSame(409);

        $measures = [];
        $bobSlot = '';
        $aliceSlot = '';
        // Whatever the order of the slots: 2 then 3 for the two small worlds, 35 for Bob (40 in all).
        $smallWorlds = 0;
        foreach ($this->reportSlots($event->getId()) as $slot) {
            $isBob = $slot['userId'] === $bob->getId();
            // Bob's giant world hosts most of the others' keys.
            $hosted = $isBob ? 35 : (0 === $smallWorlds++ ? 2 : 3);
            $measures[] = ['name' => $slot['slotName'], 'locations' => $isBob ? 800 : 100, 'ownProgression' => 20, 'hostedProgression' => $hosted];
            if ($isBob) {
                $bobSlot = $slot['slotName'];
            } else {
                $aliceSlot = $slot['slotName'];
            }
        }
        $this->generatedOutput($event->getId());
        StubSeedArchiveGateway::willMeasure($measures);
        $this->measureQueuedReport();

        $this->client->request('GET', '/api/v1/admin/events/'.$event->getId().'/balance');
        self::assertResponseIsSuccessful();
        $report = $this->data();
        self::assertSame('done', $report['status']);
        self::assertFalse($report['stale']);
        self::assertSame(3, $report['slotCount']);
        self::assertIsArray($report['slots']);
        $bobRow = $this->rowOf($report['slots'], $bobSlot);
        self::assertSame($bob->getId(), $bobRow['userId']);
        self::assertSame(800, $bobRow['locations']);
        self::assertSame(0.875, $bobRow['hostedShare']);
        self::assertSame(0.8, $bobRow['fairShare']);
        // 35 of the 40 progression items the others own.
        self::assertSame(0.875, $bobRow['othersShare']);
        self::assertSame(['hosted_over_half'], $bobRow['alerts']);
        $aliceRow = $this->rowOf($report['slots'], $aliceSlot);
        self::assertSame($alice->getId(), $aliceRow['userId']);
        self::assertSame([], $aliceRow['alerts']);

        // A YAML saved after the generation: the report is to redo.
        $registration = $this->registrationOf($event->getId(), $alice->getId());
        $slotId = $registration->getGameSlots()[0]['slotId'];
        $registration->submitSlotPlayerYaml($slotId, "name: Alice\ngame: A\n", '', new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->client->request('GET', '/api/v1/admin/events/'.$event->getId().'/balance');
        self::assertTrue($this->data()['stale']);
    }

    public function testDurationAlertsFollowTheMedianAndTheTarget(): void
    {
        $event = $this->createEvent('ArchiLAN', new \DateTimeImmutable('2027-06-01T10:00:00+00:00'), new \DateTimeImmutable('2027-06-02T10:00:00+00:00'));
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN'], 'Admin');
        $alice = $this->createUser('alice@example.com', displayName: 'Alice');
        $bob = $this->createUser('bob@example.com', displayName: 'Bob');
        $carol = $this->createUser('carol@example.com', displayName: 'Carol');
        $paced = $this->createGame('Paced', 'paced');
        $paced->estimatePace(10);
        $unknown = $this->createGame('Unknown', 'unknown');
        $this->entityManager->flush();
        foreach ([$alice, $bob] as $user) {
            $this->createRegistration($event->getId(), $user->getId(), selectedGameIds: [$paced->getId()]);
        }
        $this->createRegistration($event->getId(), $carol->getId(), selectedGameIds: [$unknown->getId()]);

        $this->loginAs($admin);
        $this->client->request('POST', '/api/v1/admin/events/'.$event->getId().'/balance');
        self::assertResponseStatusCodeSame(202);
        $measures = [];
        $slotOf = [];
        foreach ($this->reportSlots($event->getId()) as $slot) {
            $slotOf[$slot['userId']] = $slot['slotName'];
            // Alice 10 h, Bob 300 h: a median of 155 h, Bob far past the day's 19.2 h target but not twice the median.
            $locations = match ($slot['userId']) {
                $alice->getId() => 100,
                $bob->getId() => 3000,
                default => 50,
            };
            $measures[] = ['name' => $slot['slotName'], 'locations' => $locations, 'ownProgression' => 10, 'hostedProgression' => 0];
        }
        $this->generatedOutput($event->getId());
        StubSeedArchiveGateway::willMeasure($measures);
        $this->measureQueuedReport();

        $this->client->request('GET', '/api/v1/admin/events/'.$event->getId().'/balance');
        $report = $this->data();
        self::assertSame(19.2, $report['targetHours']);
        self::assertEquals(155, $report['medianHours']);
        self::assertIsArray($report['slots']);
        $bobRow = $this->rowOf($report['slots'], $slotOf[$bob->getId()]);
        self::assertSame(['duration_over_target'], $bobRow['alerts']);
        $aliceRow = $this->rowOf($report['slots'], $slotOf[$alice->getId()]);
        self::assertSame([], $aliceRow['alerts']);
        // A game without a pace keeps its size and shows no duration.
        $carolRow = $this->rowOf($report['slots'], $slotOf[$carol->getId()]);
        self::assertSame(50, $carolRow['locations']);
        self::assertIsArray($carolRow['duration']);
        self::assertNull($carolRow['duration']['hours']);
        self::assertSame('unknown', $carolRow['duration']['source']);
        self::assertNull($carolRow['medianGap']);
    }

    public function testAPlayerSeesOnlyTheirOwnLines(): void
    {
        [$event, $admin, $alice] = $this->eventWithTwoPlayers();
        $this->loginAs($admin);
        $this->client->request('POST', '/api/v1/admin/events/'.$event->getId().'/balance');
        self::assertResponseStatusCodeSame(202);

        $this->loginAs($alice);
        $this->client->request('GET', '/api/v1/events/'.$event->getId().'/my-balance');
        self::assertResponseIsSuccessful();
        $report = $this->data();
        self::assertIsArray($report['slots']);
        self::assertCount(2, $report['slots']);
        foreach ($report['slots'] as $row) {
            self::assertIsArray($row);
            self::assertSame($alice->getId(), $row['userId']);
        }
        self::assertNull($report['error']);

        $this->client->request('GET', '/api/v1/admin/events/'.$event->getId().'/balance');
        self::assertResponseStatusCodeSame(403);

        $outsider = $this->createUser('outsider@example.com');
        $this->loginAs($outsider);
        $this->client->request('GET', '/api/v1/events/'.$event->getId().'/my-balance');
        self::assertResponseStatusCodeSame(404);
    }

    public function testACrashIsRecordedWithItsReason(): void
    {
        [$event, $admin] = $this->eventWithTwoPlayers();
        $this->loginAs($admin);
        $this->client->request('POST', '/api/v1/admin/events/'.$event->getId().'/balance');
        self::assertResponseStatusCodeSame(202);

        $this->sendSigned(['event' => 'session.crashed', 'sessionId' => 'balance-event-'.$event->getId(), 'error' => 'Generation failed']);
        self::assertResponseIsSuccessful();

        $this->client->request('GET', '/api/v1/admin/events/'.$event->getId().'/balance');
        $report = $this->data();
        self::assertSame('failed', $report['status']);
        self::assertIsString($report['error']);
        self::assertNotSame('', $report['error']);

        // A failed report may be generated again at once.
        $this->client->request('POST', '/api/v1/admin/events/'.$event->getId().'/balance');
        self::assertResponseStatusCodeSame(202);
    }

    public function testAGameNeedingARomIsReportedInsteadOfGenerated(): void
    {
        [$event, $admin] = $this->eventWithTwoPlayers();
        $rom = $this->createGame('Donkey Kong Country 3', 'dkc3');
        $rom->recordDeclaredRequiredFiles([['setting' => 'DK3Settings.rom_file', 'file' => 'dkc3.sfc', 'extension' => '.sfc', 'description' => 'ROM', 'md5s' => []]]);
        $rom->markRequiredAtGeneration('DK3Settings.rom_file', 'dkc3.sfc', '.sfc', null, []);
        $carol = $this->createUser('carol@example.com', displayName: 'Carol');
        $this->createRegistration($event->getId(), $carol->getId(), selectedGameIds: [$rom->getId()]);

        $this->loginAs($admin);
        $this->client->request('POST', '/api/v1/admin/events/'.$event->getId().'/balance');
        self::assertResponseIsSuccessful();
        self::assertSame('needs_files', $this->data()['status']);
        self::assertNull(NullRunnerGateway::$lastConfigureSlots);

        $this->client->request('GET', '/api/v1/admin/events/'.$event->getId().'/balance');
        $report = $this->data();
        self::assertSame('needs_files', $report['status']);
        self::assertSame(['Donkey Kong Country 3'], $report['missingFiles']);
    }

    public function testOnlyTheOrganisersOfARunDryGenerateIt(): void
    {
        $owner = $this->createUser('owner@example.com', displayName: 'Owner');
        $guest = $this->createUser('guest@example.com', displayName: 'Guest');
        $game = $this->createGame('Game A', 'game-a');
        $now = new \DateTimeImmutable();
        $run = Run::create($owner->getId(), 'Test Run', $now);
        $run->configureGames([['gameId' => $game->getId()]], $now);
        $this->entityManager->persist($run);
        foreach ([$owner, $guest] as $user) {
            $seat = RunParticipant::create($run->getId(), $user->getId(), $now);
            $seat->replaceSlots([['slotId' => bin2hex(random_bytes(8)), 'gameId' => $game->getId()]]);
            $this->entityManager->persist($seat);
        }
        $this->entityManager->flush();

        $this->loginAs($guest);
        $this->client->request('POST', '/api/v1/runs/'.$run->getId().'/balance');
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', '/api/v1/runs/'.$run->getId().'/balance');
        self::assertResponseStatusCodeSame(403);

        $this->loginAs($owner);
        $this->client->request('POST', '/api/v1/runs/'.$run->getId().'/balance');
        self::assertResponseStatusCodeSame(202);
        $this->client->request('GET', '/api/v1/runs/'.$run->getId().'/balance');
        self::assertResponseIsSuccessful();
        $report = $this->data();
        self::assertSame('generating', $report['status']);
        self::assertNull($report['targetHours']);
        self::assertSame(2, $report['slotCount']);
    }

    /** @return array{0: Event, 1: User, 2: User, 3: User} */
    private function eventWithTwoPlayers(): array
    {
        $event = $this->createEvent('ArchiLAN', new \DateTimeImmutable('2027-06-01T10:00:00+00:00'), new \DateTimeImmutable('2027-06-02T10:00:00+00:00'));
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN'], 'Admin');
        $alice = $this->createUser('alice@example.com', displayName: 'Alice');
        $bob = $this->createUser('bob@example.com', displayName: 'Bob');
        $a = $this->game('Game A', 'game-a');
        $b = $this->game('Game B', 'game-b');
        $this->createRegistration($event->getId(), $alice->getId(), selectedGameIds: [$a->getId(), $b->getId()]);
        $this->createRegistration($event->getId(), $bob->getId(), selectedGameIds: [$b->getId()]);

        return [$event, $admin, $alice, $bob];
    }

    private function game(string $name, string $slug): Game
    {
        return $this->createGame($name, $slug);
    }

    /** @return list<array{slotName: string, playerName: string, userId: string, gameId: string, gameName: string}> */
    private function reportSlots(string $eventId): array
    {
        $report = $this->entityManager->find(BalanceReport::class, BalanceReport::idFor(BalanceReport::SCOPE_EVENT, $eventId));
        self::assertInstanceOf(BalanceReport::class, $report);

        return $report->getSlots();
    }

    private function generatedOutput(string $eventId): void
    {
        $zipPath = tempnam(sys_get_temp_dir(), 'ap563');
        self::assertIsString($zipPath);
        $zip = new \ZipArchive();
        self::assertTrue($zip->open($zipPath, \ZipArchive::OVERWRITE));
        $zip->addFromString('AP_563.archipelago', "\x03multidata");
        $zip->close();
        $contents = file_get_contents($zipPath);
        unlink($zipPath);
        self::assertIsString($contents);
        $storage = self::getContainer()->get(NullMinioStorage::class);
        self::assertInstanceOf(NullMinioStorage::class, $storage);
        $storage->upload('sessions', self::OUTPUT_KEY, $contents);

        $this->sendSigned(['event' => 'session.generated', 'sessionId' => 'balance-event-'.$eventId, 'outputKey' => self::OUTPUT_KEY]);
        self::assertResponseIsSuccessful();
    }

    /** Runs the measure job the webhook queued, as the worker would. */
    private function measureQueuedReport(): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $jobs = array_values(array_filter(
            array_map(static fn ($envelope): object => $envelope->getMessage(), iterator_to_array($transport->getSent(), false)),
            static fn (object $message): bool => $message instanceof MeasureBalanceReportJob,
        ));
        self::assertCount(1, $jobs);
        $handler = self::getContainer()->get(MeasureBalanceReportJobHandler::class);
        self::assertInstanceOf(MeasureBalanceReportJobHandler::class, $handler);
        self::assertInstanceOf(MeasureBalanceReportJob::class, $jobs[0]);
        $handler($jobs[0]);
        $this->entityManager->clear();
    }

    private function registrationOf(string $eventId, string $userId): Registration
    {
        $this->entityManager->clear();
        $registration = $this->entityManager->getRepository(Registration::class)->findOneBy(['eventId' => $eventId, 'userId' => $userId]);
        self::assertInstanceOf(Registration::class, $registration);

        return $registration;
    }

    /**
     * @param array<mixed> $rows
     *
     * @return array<mixed>
     */
    private function rowOf(array $rows, string $slotName): array
    {
        foreach ($rows as $row) {
            if (is_array($row) && $slotName === ($row['slotName'] ?? null)) {
                return $row;
            }
        }
        self::fail('No row for '.$slotName);
    }

    /** @param array<string, mixed> $payload */
    private function sendSigned(array $payload): void
    {
        $body = json_encode($payload + ['timestamp' => new \DateTimeImmutable()->format(\DateTimeInterface::RFC3339)], JSON_THROW_ON_ERROR);
        $this->client->request('POST', self::WEBHOOK_URL, [], [], [
            'HTTP_X_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, self::WEBHOOK_SECRET),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    /** @return array<mixed> */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }
}
