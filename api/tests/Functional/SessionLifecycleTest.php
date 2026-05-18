<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Communications\Application\SessionRunningMessage;
use App\Events\Domain\Event;
use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameCatalogSync;
use App\Identity\Domain\User;
use App\PersonalRuns\Domain\Run;
use App\Realtime\Infrastructure\SpyHub;
use App\Registrations\Domain\Registration;
use App\Sessions\Application\Message\RestartRunJob;
use App\Sessions\Application\Message\StopRunJob;
use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionSlot;
use Doctrine\ORM\Tools\SchemaTool;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

final class SessionLifecycleTest extends FunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->hub()->reset();

        $metadata = [
            $this->entityManager->getClassMetadata(User::class),
            $this->entityManager->getClassMetadata(Session::class),
            $this->entityManager->getClassMetadata(SessionSlot::class),
            $this->entityManager->getClassMetadata(Registration::class),
            $this->entityManager->getClassMetadata(Game::class),
            $this->entityManager->getClassMetadata(GameCatalogSync::class),
            $this->entityManager->getClassMetadata(Run::class),
            $this->entityManager->getClassMetadata(Event::class),
        ];
        $schemaTool = new SchemaTool($this->entityManager);
        $schemaTool->dropSchema($metadata);
        $schemaTool->createSchema($metadata);
    }

    public function testAdminCreatesSessionInDraftStatus(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', [
            'slots' => [
                ['registrationId' => 'reg-1', 'gameId' => 'game-1', 'slotName' => 'Alice_HK1'],
                ['registrationId' => 'reg-2', 'gameId' => 'game-2', 'slotName' => 'Bob_ALttP1'],
            ],
        ]);

        self::assertResponseStatusCodeSame(201);
        $response = $this->decodedJsonResponse();
        $data = $response['data'];
        self::assertIsArray($data);
        self::assertSame('draft', $data['status']);
        self::assertSame('evt-001', $data['eventId']);
        self::assertNull($data['host']);
        self::assertNull($data['port']);
    }

    public function testCreateSessionPublishesMercureEvent(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', ['slots' => []]);

        self::assertResponseStatusCodeSame(201);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        $hub = $this->hub();
        self::assertCount(1, $hub->published);
        self::assertSame(
            sprintf('/sessions/%s', $sessionId),
            $hub->published[0]->getTopics()[0],
        );
    }

    public function testAdminCanGetSession(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', [
            'slots' => [
                ['registrationId' => 'reg-1', 'gameId' => 'game-1', 'slotName' => 'Alice_HK1'],
            ],
        ]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/sessions/%s', $sessionId));

        self::assertResponseIsSuccessful();
        $response = $this->decodedJsonResponse();
        $responseData = $response['data'];
        self::assertIsArray($responseData);
        $session = $responseData['session'];
        self::assertIsArray($session);
        self::assertSame($sessionId, $session['id']);
        $slots = $responseData['slots'];
        self::assertIsArray($slots);
        self::assertCount(1, $slots);
        $firstSlot = $slots[0];
        self::assertIsArray($firstSlot);
        self::assertSame('Alice_HK1', $firstSlot['slotName']);
    }

    public function testGetUnknownSessionReturns404(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/sessions/nonexistent');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAdminCanAdvanceSessionThroughStateMachine(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', ['slots' => []]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        // draft → validating
        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/sessions/%s/status', $sessionId), ['status' => 'validating']);
        self::assertResponseIsSuccessful();
        $patchResponse = $this->decodedJsonResponse();
        $patchData = $patchResponse['data'];
        self::assertIsArray($patchData);
        self::assertSame('validating', $patchData['status']);

        // validating → ready
        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/sessions/%s/status', $sessionId), ['status' => 'ready']);
        self::assertResponseIsSuccessful();

        // ready → generating
        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/sessions/%s/status', $sessionId), ['status' => 'generating']);
        self::assertResponseIsSuccessful();

        // generating → generated
        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/sessions/%s/status', $sessionId), ['status' => 'generated']);
        self::assertResponseIsSuccessful();

        // generated → launching
        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/sessions/%s/status', $sessionId), ['status' => 'launching']);
        self::assertResponseIsSuccessful();

        // launching → running (runner callback sets host/port/password)
        $this->patchStatus($sessionId, 'running');
        self::assertResponseIsSuccessful();
        $runningResponse = $this->decodedJsonResponse();
        $runningData = $runningResponse['data'];
        self::assertIsArray($runningData);
        self::assertSame('running', $runningData['status']);
    }

    public function testFullStateMachinePublishesMercureEventPerTransition(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', ['slots' => []]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        // Verify create published 1 event (before next request reboots the kernel)
        self::assertCount(1, $this->hub()->published);
        self::assertSame(sprintf('/sessions/%s', $sessionId), $this->hub()->published[0]->getTopics()[0]);

        // Each PATCH reboots the kernel before handling - so each transition gets a fresh SpyHub.
        // Verify that every transition publishes exactly 1 Mercure event on its own hub instance.
        $statuses = ['validating', 'ready', 'generating', 'generated', 'launching', 'running', 'stopped'];
        foreach ($statuses as $status) {
            $this->patchStatus($sessionId, $status);
            self::assertResponseIsSuccessful(sprintf('transition to %s failed', $status));
            $published = $this->hub()->published;
            self::assertCount(1, $published, sprintf('Expected 1 Mercure event for transition to %s', $status));
            self::assertSame(sprintf('/sessions/%s', $sessionId), $published[0]->getTopics()[0]);
        }
    }

    public function testInvalidTransitionReturns409(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', ['slots' => []]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        // draft → running is not allowed
        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/sessions/%s/status', $sessionId), ['status' => 'running']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testCrashedSessionCanRelaunch(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', ['slots' => []]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        foreach (['validating', 'ready', 'generating', 'generated', 'launching', 'running', 'crashed'] as $s) {
            $this->patchStatus($sessionId, $s);
            self::assertResponseIsSuccessful();
        }

        // crashed → launching is allowed
        $this->patchStatus($sessionId, 'launching');
        self::assertResponseIsSuccessful();
        $launchingResponse = $this->decodedJsonResponse();
        $launchingData = $launchingResponse['data'];
        self::assertIsArray($launchingData);
        self::assertSame('launching', $launchingData['status']);
    }

    public function testAdminDeleteSessionTransitionsToStopped(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', ['slots' => []]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        foreach (['validating', 'ready', 'generating', 'generated', 'launching', 'running'] as $s) {
            $this->patchStatus($sessionId, $s);
        }

        $this->client->jsonRequest('DELETE', sprintf('/api/v1/admin/sessions/%s', $sessionId));
        self::assertResponseStatusCodeSame(204);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/sessions/%s', $sessionId));
        $getResponse = $this->decodedJsonResponse();
        $getResponseData = $getResponse['data'];
        self::assertIsArray($getResponseData);
        $sessionData = $getResponseData['session'];
        self::assertIsArray($sessionData);
        self::assertSame('stopped', $sessionData['status']);
    }

    public function testRunnerCallbackRequiresCorrectSecret(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', ['slots' => []]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        $this->client->jsonRequest('POST', sprintf('/api/v1/internal/sessions/%s/runner-callback', $sessionId), ['status' => 'validating']);
        self::assertResponseStatusCodeSame(401);

        $this->client->request(
            'POST',
            sprintf('/api/v1/internal/sessions/%s/runner-callback', $sessionId),
            [],
            [],
            ['HTTP_X_INTERNAL_SECRET' => 'wrong-secret', 'CONTENT_TYPE' => 'application/json'],
            json_encode(['status' => 'validating'], JSON_THROW_ON_ERROR),
        );
        self::assertResponseStatusCodeSame(401);
    }

    public function testRunnerCallbackTransitionsSession(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', ['slots' => []]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        $this->callbackAs($sessionId, 'validating');
        self::assertResponseIsSuccessful();
        $callbackResponse = $this->decodedJsonResponse();
        $callbackData = $callbackResponse['data'];
        self::assertIsArray($callbackData);
        self::assertSame('validating', $callbackData['status']);
    }

    public function testRunnerCallbackSetsHostPortPasswordOnRunning(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', ['slots' => []]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        foreach (['validating', 'ready', 'generating', 'generated', 'launching'] as $s) {
            $this->callbackAs($sessionId, $s);
        }

        $this->callbackAs($sessionId, 'running', host: '10.0.0.1', port: 9042, password: 'supersecret');
        self::assertResponseIsSuccessful();

        $runningResponse = $this->decodedJsonResponse();
        $data = $runningResponse['data'];
        self::assertIsArray($data);
        self::assertSame('running', $data['status']);
        self::assertSame('10.0.0.1', $data['host']);
        self::assertSame(9042, $data['port']);
        self::assertSame('supersecret', $data['password']);
        self::assertNotNull($data['startedAt']);
    }

    public function testRunnerCallbackRejectsRunningWithoutConnectionDetails(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', ['slots' => []]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        foreach (['validating', 'ready', 'generating', 'generated', 'launching'] as $s) {
            $this->callbackAs($sessionId, $s);
        }

        $this->callbackAs($sessionId, 'running');

        self::assertResponseStatusCodeSame(409);
        $errorResponse = $this->decodedJsonResponse();
        $error = $errorResponse['error'];
        self::assertIsArray($error);
        self::assertSame('invalid_transition', $error['code']);
    }

    public function testRunnerCallbackReturns404ForUnknownSession(): void
    {
        $this->callbackAs('nonexistent', 'validating');
        self::assertResponseStatusCodeSame(404);
    }

    public function testRunnerCallbackReturns409ForInvalidTransition(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', ['slots' => []]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        // draft → running not allowed
        $this->callbackAs($sessionId, 'running');
        self::assertResponseStatusCodeSame(409);
    }

    public function testStoppedAtIsSetOnTerminalTransitions(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', ['slots' => []]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        foreach (['validating', 'ready', 'generating', 'generated', 'launching', 'running', 'stopped'] as $s) {
            $this->patchStatus($sessionId, $s);
        }

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/sessions/%s', $sessionId));
        $getResponse = $this->decodedJsonResponse();
        $getResponseData = $getResponse['data'];
        self::assertIsArray($getResponseData);
        $sessionData = $getResponseData['session'];
        self::assertIsArray($sessionData);
        self::assertNotNull($sessionData['stoppedAt']);
    }

    public function testSessionRecordsArePreservedAfterStopped(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', [
            'slots' => [
                ['registrationId' => 'reg-1', 'gameId' => 'game-1', 'slotName' => 'Alice_HK1'],
            ],
        ]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        foreach (['validating', 'ready', 'generating', 'generated', 'launching', 'running', 'stopped'] as $s) {
            $this->patchStatus($sessionId, $s);
        }

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/sessions/%s', $sessionId));
        self::assertResponseIsSuccessful();

        $getResponse = $this->decodedJsonResponse();
        $data = $getResponse['data'];
        self::assertIsArray($data);
        $session = $data['session'];
        self::assertIsArray($session);
        self::assertSame('stopped', $session['status']);
        $slots = $data['slots'];
        self::assertIsArray($slots);
        self::assertCount(1, $slots);
        $firstSlot = $slots[0];
        self::assertIsArray($firstSlot);
        self::assertSame('Alice_HK1', $firstSlot['slotName']);
    }

    public function testListSessionsForEvent(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', ['slots' => []]);
        self::assertResponseStatusCodeSame(201);
        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', ['slots' => []]);
        self::assertResponseStatusCodeSame(201);

        $this->client->jsonRequest('GET', '/api/v1/admin/events/evt-001/sessions');
        self::assertResponseIsSuccessful();
        $listResponse = $this->decodedJsonResponse();
        $data = $listResponse['data'];
        self::assertIsArray($data);
        self::assertCount(2, $data);
        $firstSession = $data[0];
        self::assertIsArray($firstSession);
        self::assertSame('draft', $firstSession['status']);
    }

    public function testListSessionsReturnsEmptyForUnknownEvent(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/events/no-such-event/sessions');
        self::assertResponseIsSuccessful();
        $emptyResponse = $this->decodedJsonResponse();
        self::assertSame([], $emptyResponse['data']);
    }

    public function testBuilderReturnsEmptyForEventWithNoRegistrations(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('GET', '/api/v1/admin/events/evt-001/sessions/builder');
        self::assertResponseIsSuccessful();
        $builderResponse = $this->decodedJsonResponse();
        $data = $builderResponse['data'];
        self::assertIsArray($data);
        self::assertSame([], $data['registrations']);
    }

    public function testBuilderReturnsPlayerYamlForRunnerPreflight(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $user = $this->createPlayer('alice@example.org', 'Alice');
        $game = $this->makeGame('Hollow Knight', 'Hollow Knight');

        $registration = $this->createRegistration('evt-001', $user->getId());
        $registration->replaceSlots([
            ['slotId' => 'slot-1', 'gameId' => $game->getId()],
        ], new \DateTimeImmutable('2026-05-02T10:00:00+00:00'));
        $registration->setSlotPlayerYaml('slot-1', "name: PlayerName\ngame: Hollow Knight\n", 'test-hash', new \DateTimeImmutable('2026-05-02T10:00:00+00:00'));
        $this->entityManager->flush();

        $this->client->jsonRequest('GET', '/api/v1/admin/events/evt-001/sessions/builder');

        self::assertResponseIsSuccessful();
        $builderResponse = $this->decodedJsonResponse();
        $builderData = $builderResponse['data'];
        self::assertIsArray($builderData);
        $registrations = $builderData['registrations'];
        self::assertIsArray($registrations);
        $firstReg = $registrations[0];
        self::assertIsArray($firstReg);
        $regSlots = $firstReg['slots'];
        self::assertIsArray($regSlots);
        $slot = $regSlots[0];
        self::assertIsArray($slot);
        self::assertSame('Hollow Knight', $slot['archipelagoGameName']);
        $playerYaml = $slot['playerYaml'];
        self::assertIsString($playerYaml);
        self::assertStringContainsString('Hollow Knight', $playerYaml);
    }

    public function testCreateSessionRejectsDuplicateSlotNames(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', [
            'slots' => [
                ['registrationId' => 'reg-1', 'gameId' => 'game-1', 'slotName' => 'Alice_HK1'],
                ['registrationId' => 'reg-2', 'gameId' => 'game-2', 'slotName' => 'Alice_HK1'],
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        $errorResponse422a = $this->decodedJsonResponse();
        $error422a = $errorResponse422a['error'];
        self::assertIsArray($error422a);
        self::assertSame('session_preflight_failed', $error422a['code']);
    }

    public function testCreateSessionRejectsSlotWithoutArchipelagoGameName(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $user = $this->createPlayer('bob@example.org', 'Bob');
        $game = $this->makeGame('Hollow Knight', null);
        $registration = $this->createRegistration('evt-001', $user->getId());
        $registration->replaceSlots([
            ['slotId' => 'slot-1', 'gameId' => $game->getId()],
        ], new \DateTimeImmutable('2026-05-02T10:00:00+00:00'));
        $this->entityManager->flush();

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', [
            'slots' => [
                ['registrationId' => $registration->getId(), 'gameId' => $game->getId(), 'slotName' => 'Bob_HK1', 'slotId' => 'slot-1'],
            ],
        ]);

        self::assertResponseStatusCodeSame(422);
        $errorResponse422b = $this->decodedJsonResponse();
        $error422b = $errorResponse422b['error'];
        self::assertIsArray($error422b);
        self::assertSame('session_preflight_failed', $error422b['code']);
    }

    public function testGenerateOrchestrationTransitionsSessionToGenerating(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', ['slots' => []]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        // /generate requires ready status - advance through validating → ready first
        foreach (['validating', 'ready'] as $s) {
            $this->patchStatus($sessionId, $s);
        }

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/generate', $sessionId));
        self::assertResponseIsSuccessful();
        $generateResponse = $this->decodedJsonResponse();
        $generateData = $generateResponse['data'];
        self::assertIsArray($generateData);
        self::assertSame('generating', $generateData['status']);
    }

    public function testLaunchOrchestrationTransitionsSessionToLaunching(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', ['slots' => []]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        // Advance to generated via direct status patch
        foreach (['validating', 'ready', 'generating', 'generated'] as $s) {
            $this->patchStatus($sessionId, $s);
        }

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/launch', $sessionId));
        self::assertResponseIsSuccessful();
        $launchResponse = $this->decodedJsonResponse();
        $launchData = $launchResponse['data'];
        self::assertIsArray($launchData);
        self::assertSame('launching', $launchData['status']);
    }

    public function testStopOrchestrationTransitionsSessionToStopped(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', ['slots' => []]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        foreach (['validating', 'ready', 'generating', 'generated', 'launching', 'running'] as $s) {
            $this->patchStatus($sessionId, $s);
        }

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/stop', $sessionId));
        self::assertResponseIsSuccessful();
        $stopResponse = $this->decodedJsonResponse();
        $stopData = $stopResponse['data'];
        self::assertIsArray($stopData);
        self::assertSame('stopped', $stopData['status']);
    }

    public function testRestartOrchestrationTransitionsSessionFromCrashedToLaunching(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', ['slots' => []]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        foreach (['validating', 'ready', 'generating', 'generated', 'launching', 'running', 'crashed'] as $s) {
            $this->patchStatus($sessionId, $s);
        }

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/restart', $sessionId));
        self::assertResponseIsSuccessful();
        $restartResponse = $this->decodedJsonResponse();
        $restartData = $restartResponse['data'];
        self::assertIsArray($restartData);
        self::assertSame('launching', $restartData['status']);
    }

    public function testGenerateReturns404ForUnknownSession(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/sessions/nonexistent/generate');
        self::assertResponseStatusCodeSame(404);
    }

    public function testLaunchReturns409WhenNotGenerated(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', ['slots' => []]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/launch', $sessionId));
        self::assertResponseStatusCodeSame(409);
    }

    public function testTransitionToRunningMarksSessionAsNotified(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', ['slots' => []]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        foreach (['validating', 'ready', 'generating', 'generated', 'launching'] as $s) {
            $this->patchStatus($sessionId, $s);
        }

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/sessions/%s', $sessionId));
        $beforeResponse = $this->decodedJsonResponse();
        $beforeData = $beforeResponse['data'];
        self::assertIsArray($beforeData);
        $beforeSession = $beforeData['session'];
        self::assertIsArray($beforeSession);
        self::assertNull($beforeSession['notifiedAt']);

        $this->callbackAs($sessionId, 'running', host: '10.0.0.1', port: 9042, password: 'secret');
        self::assertResponseIsSuccessful();

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/sessions/%s', $sessionId));
        $afterResponse = $this->decodedJsonResponse();
        $afterData = $afterResponse['data'];
        self::assertIsArray($afterData);
        $afterSession = $afterData['session'];
        self::assertIsArray($afterSession);
        self::assertNotNull($afterSession['notifiedAt']);
    }

    public function testTransitionToRunningDispatchesOneMessagePerRegistrant(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $user = $this->createPlayer('player@example.org', 'Jean');
        $registration = $this->createRegistration('evt-001', $user->getId());

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', [
            'slots' => [
                ['registrationId' => $registration->getId(), 'gameId' => 'game-1', 'slotName' => 'Jean_HK1'],
            ],
        ]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        foreach (['validating', 'ready', 'generating', 'generated', 'launching'] as $s) {
            $this->patchStatus($sessionId, $s);
        }

        $this->callbackAs($sessionId, 'running', host: '10.0.0.1', port: 9042, password: 'secret');
        self::assertResponseIsSuccessful();

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        $sent = $transport->getSent();
        self::assertCount(1, $sent);
        /** @var SessionRunningMessage $message */
        $message = $sent[0]->getMessage();
        self::assertInstanceOf(SessionRunningMessage::class, $message);
        self::assertSame($sessionId, $message->sessionId);
        self::assertSame($registration->getId(), $message->registrationId);
        self::assertSame('player@example.org', $message->userEmail);
        self::assertSame(['Jean_HK1'], $message->slotNames);
        self::assertSame('10.0.0.1', $message->host);
        self::assertSame(9042, $message->port);
        self::assertSame('secret', $message->password);
    }

    public function testNoDuplicateNotificationOnRestartAfterCrash(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $user = $this->createPlayer('player2@example.org', 'Bob');
        $registration = $this->createRegistration('evt-001', $user->getId());

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', [
            'slots' => [
                ['registrationId' => $registration->getId(), 'gameId' => 'game-1', 'slotName' => 'Bob_HK1'],
            ],
        ]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        // First run: draft → running → crashed
        foreach (['validating', 'ready', 'generating', 'generated', 'launching', 'running', 'crashed'] as $s) {
            $this->patchStatus($sessionId, $s);
        }

        // Restart: crashed → launching → running (no new notification)
        $this->patchStatus($sessionId, 'launching');
        $this->callbackAs($sessionId, 'running', host: '10.0.0.2', port: 9043, password: 'new-secret');
        self::assertResponseIsSuccessful();

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertCount(0, $transport->getSent(), 'No notification should be re-sent after restart');
    }

    public function testRunnerCallbackStoresBridgePort(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', ['slots' => []]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        foreach (['validating', 'ready', 'generating', 'generated', 'launching'] as $s) {
            $this->patchStatus($sessionId, $s);
        }

        $this->callbackAs($sessionId, 'running', host: '10.0.0.1', port: 38281, password: 'secret', bridgePort: 5000);
        self::assertResponseIsSuccessful();

        $data = $this->decodedJsonResponse()['data'];
        self::assertIsArray($data);
        self::assertSame('running', $data['status']);
        self::assertSame(38281, $data['port']);
        self::assertSame(5000, $data['bridgePort']);
    }

    public function testStopOrchestrationDispatchesStopJobWithBothPorts(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        // Advance to launching via HTTP, then set running state directly with bridgePort via entity
        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', ['slots' => []]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        foreach (['validating', 'ready', 'generating', 'generated', 'launching'] as $s) {
            $this->patchStatus($sessionId, $s);
        }

        // Set running state with bridge_port directly via entity (bypasses HTTP to avoid transport noise)
        $session = $this->entityManager->find(Session::class, $sessionId);
        self::assertInstanceOf(Session::class, $session);
        $session->transition(Session::STATUS_RUNNING, new \DateTimeImmutable(), '10.0.0.1', 38281, 'secret', 5000);
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/stop', $sessionId));
        self::assertResponseIsSuccessful('stop endpoint failed: '.$this->client->getResponse()->getContent());

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_server');
        $sent = $transport->getSent();
        $classes = array_map(static fn ($e) => $e->getMessage()::class, $sent);
        $stopMessages = array_values(array_filter($sent, static fn ($e) => $e->getMessage() instanceof StopRunJob));
        self::assertCount(1, $stopMessages, sprintf('Expected 1 StopRunJob, got %d messages of types: [%s]', count($sent), implode(', ', $classes)));
        /** @var StopRunJob $stopJob */
        $stopJob = $stopMessages[0]->getMessage();
        self::assertSame($sessionId, $stopJob->sessionId);
        self::assertSame(38281, $stopJob->port);
        self::assertSame(5000, $stopJob->bridgePort);
    }

    public function testRestartOrchestrationDispatchesRestartJobWithBothPorts(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', ['slots' => []]);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        foreach (['validating', 'ready', 'generating', 'generated', 'launching'] as $s) {
            $this->patchStatus($sessionId, $s);
        }

        // Set running state with bridge_port directly via entity, then crash it
        $session = $this->entityManager->find(Session::class, $sessionId);
        self::assertInstanceOf(Session::class, $session);
        $session->transition(Session::STATUS_RUNNING, new \DateTimeImmutable(), '10.0.0.1', 38281, 'pw', 5000);
        $session->transition(Session::STATUS_CRASHED, new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->entityManager->clear();

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/sessions/%s/restart', $sessionId));
        self::assertResponseIsSuccessful();

        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_server');
        $sent = $transport->getSent();
        $restartMessages = array_values(array_filter($sent, static fn ($e) => $e->getMessage() instanceof RestartRunJob));
        self::assertCount(1, $restartMessages);
        /** @var RestartRunJob $restartJob */
        $restartJob = $restartMessages[0]->getMessage();
        self::assertSame($sessionId, $restartJob->sessionId);
        self::assertSame(38281, $restartJob->port);
        self::assertSame(5000, $restartJob->bridgePort);
        self::assertSame('pw', $restartJob->password);
    }

    public function testSessionCreationWithSlotIdPreservesIt(): void
    {
        $admin = $this->createAdmin();
        $this->loginAs($admin);

        $this->client->jsonRequest('POST', '/api/v1/admin/events/evt-001/sessions', [
            'slots' => [
                ['registrationId' => 'reg-1', 'gameId' => 'game-1', 'slotName' => 'Alice_HK1', 'slotId' => 'slot-uuid-abc'],
            ],
        ]);
        self::assertResponseStatusCodeSame(201);
        $createResponse = $this->decodedJsonResponse();
        $createData = $createResponse['data'];
        self::assertIsArray($createData);
        $sessionId = $createData['id'];
        self::assertIsString($sessionId);

        $this->client->jsonRequest('GET', sprintf('/api/v1/admin/sessions/%s', $sessionId));
        $getResponse = $this->decodedJsonResponse();
        $getResponseData = $getResponse['data'];
        self::assertIsArray($getResponseData);
        $slots = $getResponseData['slots'];
        self::assertIsArray($slots);
        $firstSlot = $slots[0];
        self::assertIsArray($firstSlot);
        self::assertSame('slot-uuid-abc', $firstSlot['slotId']);
    }

    private function hub(): SpyHub
    {
        $hub = static::getContainer()->get(SpyHub::class);
        self::assertInstanceOf(SpyHub::class, $hub);

        return $hub;
    }

    private function createAdmin(): User
    {
        return $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
    }

    private function createPlayer(string $email, string $displayName): User
    {
        return $this->createUser($email, ['ROLE_USER'], $displayName);
    }

    private function makeGame(string $name, ?string $archipelagoGameName): Game
    {
        $now = new \DateTimeImmutable('2026-05-02T10:00:00+00:00');
        $slug = strtolower(str_replace(' ', '-', $name)).'-'.bin2hex(random_bytes(2));
        $game = $this->createGame($name, $slug);

        if (null !== $archipelagoGameName) {
            $game->configureApworld('test-key', 'test-hash', $archipelagoGameName, '', $now);
            $this->entityManager->flush();
        }

        return $game;
    }

    private function patchStatus(string $sessionId, string $status): void
    {
        $body = ['status' => $status];
        if ('running' === $status) {
            $body['host'] = '10.0.0.1';
            $body['port'] = 9042;
            $body['password'] = 'secret';
        }

        $this->client->jsonRequest('PATCH', sprintf('/api/v1/admin/sessions/%s/status', $sessionId), $body);
    }

    private function callbackAs(
        string $sessionId,
        string $status,
        ?string $host = null,
        ?int $port = null,
        ?string $password = null,
        ?int $bridgePort = null,
    ): void {
        $body = array_filter(
            ['status' => $status, 'host' => $host, 'port' => $port, 'password' => $password, 'bridge_port' => $bridgePort],
            fn ($v) => null !== $v,
        );

        $this->client->request(
            'POST',
            sprintf('/api/v1/internal/sessions/%s/runner-callback', $sessionId),
            [],
            [],
            ['HTTP_X_INTERNAL_SECRET' => 'test-runner-secret', 'CONTENT_TYPE' => 'application/json'],
            json_encode($body, JSON_THROW_ON_ERROR),
        );
    }
}
