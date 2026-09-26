<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Application\Message\PostApworldIncidentToStaffChannelJob;
use App\GameSelection\Application\Message\StaffAlertEvent;
use App\GameSelection\Domain\Entity\ApworldIncident;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Enum\ApworldIncidentType;
use App\Identity\Domain\Entity\User;
use App\Sessions\Infrastructure\Double\NullRunnerGateway;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Story 38.3: the admin API behind the apworld health page.
 */
final class AdminApworldIncidentControllerTest extends FunctionalTestCase
{
    private const string CRYSTAL_ERROR = "Traceback (most recent call last):\n  File \"x.py\", line 1\nFill.FillError: Could not access required locations for accessibility check.";

    private User $admin;
    private Game $crystal;
    private Game $beatSaber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Jean', slug: 'jean');
        $this->crystal = $this->createGame('Crystal Project', 'crystal-project');
        $this->beatSaber = $this->createGame('Beat Saber', 'beat-saber');
    }

    protected function tearDown(): void
    {
        NullRunnerGateway::reset();
        parent::tearDown();
    }

    public function testListReturnsActiveIncidentsOldestFirstWithGameAndAdminNames(): void
    {
        $this->incident('incident-beat', $this->beatSaber, '2026-09-24 12:00:00+00:00', 'FileNotFoundError: ranked_maps.json');
        $crystal = $this->incident('incident-crystal', $this->crystal, '2026-09-20 10:00:00+00:00', self::CRYSTAL_ERROR);
        $crystal->acknowledge($this->admin->getId(), new \DateTimeImmutable('2026-09-21 09:00:00+00:00'));
        $closed = $this->incident('incident-closed', $this->crystal, '2026-09-01 10:00:00+00:00', 'old', 'other-hash');
        $closed->resolve(new \DateTimeImmutable('2026-09-02 10:00:00+00:00'), null);
        $this->entityManager->flush();
        $this->loginAs($this->admin);

        $this->client->request('GET', '/api/v1/admin/apworld-incidents');

        self::assertResponseStatusCodeSame(200);
        $data = $this->listedIncidents();
        self::assertSame(['incident-crystal', 'incident-beat'], array_column($data, 'id'));
        self::assertSame('Crystal Project', $data[0]['gameName']);
        self::assertSame('acknowledged', $data[0]['status']);
        self::assertSame('preflight_failed', $data[0]['type']);
        self::assertSame(['id' => $this->admin->getId(), 'displayName' => 'Jean'], $data[0]['acknowledgedBy']);
        self::assertSame(self::CRYSTAL_ERROR, $data[0]['error']);
        self::assertIsString($data[0]['summary']);
        self::assertStringStartsWith('Fill.FillError', $data[0]['summary']);
        self::assertSame(1, $data[0]['occurrences']);
        self::assertNull($data[1]['acknowledgedBy']);
    }

    public function testClosedFilterReturnsClosedIncidentsWithHowTheyWereClosed(): void
    {
        $auto = $this->incident('incident-auto', $this->crystal, '2026-09-01 10:00:00+00:00', 'a', 'hash-a');
        $auto->resolve(new \DateTimeImmutable('2026-09-02 10:00:00+00:00'), null);
        $ignored = $this->incident('incident-ignored', $this->beatSaber, '2026-09-03 10:00:00+00:00', 'b');
        $ignored->ignore(new \DateTimeImmutable('2026-09-04 10:00:00+00:00'), $this->admin->getId());
        $this->incident('incident-open', $this->crystal, '2026-09-05 10:00:00+00:00', 'c');
        $this->entityManager->flush();
        $this->loginAs($this->admin);

        $this->client->request('GET', '/api/v1/admin/apworld-incidents?status=closed');

        self::assertResponseStatusCodeSame(200);
        $data = $this->listedIncidents();
        self::assertSame(['incident-ignored', 'incident-auto'], array_column($data, 'id'), 'most recently closed first');
        self::assertSame('ignored', $data[0]['status']);
        self::assertSame(['id' => $this->admin->getId(), 'displayName' => 'Jean'], $data[0]['closedBy']);
        self::assertFalse($data[0]['closedAutomatically']);
        self::assertTrue($data[1]['closedAutomatically']);
        self::assertNull($data[1]['closedBy']);
    }

    public function testGameFilterNarrowsTheList(): void
    {
        $this->incident('incident-crystal', $this->crystal, '2026-09-20 10:00:00+00:00', 'a');
        $this->incident('incident-beat', $this->beatSaber, '2026-09-21 10:00:00+00:00', 'b');
        $this->entityManager->flush();
        $this->loginAs($this->admin);

        $this->client->request('GET', '/api/v1/admin/apworld-incidents?gameId='.$this->beatSaber->getId());

        $data = $this->listedIncidents();
        self::assertSame(['incident-beat'], array_column($data, 'id'));
    }

    public function testSummaryCountsActiveIncidentsAndThoseNobodyHasTaken(): void
    {
        $this->incident('incident-open', $this->crystal, '2026-09-20 10:00:00+00:00', 'a');
        $taken = $this->incident('incident-taken', $this->beatSaber, '2026-09-21 10:00:00+00:00', 'b');
        $taken->acknowledge($this->admin->getId(), new \DateTimeImmutable('2026-09-22 10:00:00+00:00'));
        $this->entityManager->flush();
        $this->loginAs($this->admin);

        $this->client->request('GET', '/api/v1/admin/apworld-incidents/summary');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['active' => 2, 'unacknowledged' => 1], $this->decodedJsonResponse()['data'] ?? null);
    }

    public function testSweepProgressSaysHowManyApworldsWereTestedOnTheCurrentImage(): void
    {
        // Story 38.9: how far the rolling test has come after a new image.
        $this->crystal->configureApworld('a.apworld', 'hash-crystal', 'Crystal Project', "game: Crystal Project\n", new \DateTimeImmutable());
        $this->beatSaber->configureApworld('b.apworld', 'hash-beat', 'Beat Saber', "game: Beat Saber\n", new \DateTimeImmutable());
        $this->entityManager->flush();
        NullRunnerGateway::$runtime = ['apImage' => 'ghcr.io/archilan-dev/archipelago:0.16.1', 'apImageId' => 'sha256:current'];
        NullRunnerGateway::$apworldPreflights = [
            'hash-crystal' => ['status' => 'passed', 'error' => '', 'checkedAt' => '2026-09-26T05:00:00Z', 'overridden' => false, 'blocks' => false, 'image' => 'ghcr.io/archilan-dev/archipelago:0.16.1', 'imageId' => 'sha256:current'],
        ];
        $this->loginAs($this->admin);

        $this->client->request('GET', '/api/v1/admin/apworld-incidents/sweep-progress');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['currentImage' => 'ghcr.io/archilan-dev/archipelago:0.16.1', 'testedOnCurrentImage' => 1, 'total' => 2], $this->decodedJsonResponse()['data'] ?? null);
    }

    public function testSweepProgressIsNullWhenTheRunnerDoesNotSay(): void
    {
        $this->loginAs($this->admin);

        $this->client->request('GET', '/api/v1/admin/apworld-incidents/sweep-progress');

        self::assertResponseStatusCodeSame(200);
        $body = $this->decodedJsonResponse();
        self::assertArrayHasKey('data', $body);
        self::assertNull($body['data']);
    }

    public function testAcknowledgeRecordsTheCurrentAdminAndQueuesTheStaffAlert(): void
    {
        $this->incident('incident-crystal', $this->crystal, '2026-09-20 10:00:00+00:00', 'a');
        $this->entityManager->flush();
        $this->loginAs($this->admin);

        $this->client->request('POST', '/api/v1/admin/apworld-incidents/incident-crystal/acknowledge');

        self::assertResponseStatusCodeSame(200);
        $this->entityManager->clear();
        $incident = $this->entityManager->find(ApworldIncident::class, 'incident-crystal');
        self::assertSame($this->admin->getId(), $incident?->getAcknowledgedBy());

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertEquals(
            [new PostApworldIncidentToStaffChannelJob('incident-crystal', StaffAlertEvent::Acknowledged)],
            array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()),
        );
    }

    public function testResolveAndIgnoreCloseTheIncident(): void
    {
        $this->incident('incident-a', $this->crystal, '2026-09-20 10:00:00+00:00', 'a');
        $this->incident('incident-b', $this->beatSaber, '2026-09-20 10:00:00+00:00', 'b');
        $this->entityManager->flush();
        $this->loginAs($this->admin);

        $this->client->request('POST', '/api/v1/admin/apworld-incidents/incident-a/resolve');
        self::assertResponseStatusCodeSame(200);
        $this->client->request('POST', '/api/v1/admin/apworld-incidents/incident-b/ignore');
        self::assertResponseStatusCodeSame(200);

        $this->client->request('GET', '/api/v1/admin/apworld-incidents');
        self::assertSame([], $this->decodedJsonResponse()['data'] ?? null);
    }

    public function testForbiddenTransitionReturns409(): void
    {
        $incident = $this->incident('incident-crystal', $this->crystal, '2026-09-20 10:00:00+00:00', 'a');
        $incident->resolve(new \DateTimeImmutable('2026-09-21 10:00:00+00:00'), null);
        $this->entityManager->flush();
        $this->loginAs($this->admin);

        $this->client->request('POST', '/api/v1/admin/apworld-incidents/incident-crystal/acknowledge');

        self::assertResponseStatusCodeSame(409);
        self::assertSame('incident_closed', $this->errorCode());
    }

    public function testUnknownIncidentReturns404(): void
    {
        $this->loginAs($this->admin);

        $this->client->request('POST', '/api/v1/admin/apworld-incidents/unknown/resolve');

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonAdminIsRefused(): void
    {
        $this->loginAs($this->createUser('player@example.org'));

        $this->client->request('GET', '/api/v1/admin/apworld-incidents');
        self::assertResponseStatusCodeSame(403);

        $this->client->request('POST', '/api/v1/admin/apworld-incidents/any/acknowledge');
        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function listedIncidents(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $rows = [];
        foreach ($data as $row) {
            self::assertIsArray($row);
            $typed = [];
            foreach ($row as $key => $value) {
                self::assertIsString($key);
                $typed[$key] = $value;
            }
            $rows[] = $typed;
        }

        return $rows;
    }

    private function errorCode(): mixed
    {
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);

        return $error['code'] ?? null;
    }

    private function incident(string $id, Game $game, string $openedAt, string $error, string $hash = 'hash-served'): ApworldIncident
    {
        $incident = ApworldIncident::open($id, $game->getId(), $hash, ApworldIncidentType::PreflightFailed, $error, new \DateTimeImmutable($openedAt));
        $this->entityManager->persist($incident);

        return $incident;
    }
}
