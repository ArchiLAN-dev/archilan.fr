<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Entity\Game;
use App\Identity\Domain\Entity\User;
use App\Registrations\Domain\Entity\Registration;
use App\Sessions\Infrastructure\Double\NullRunnerGateway;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/** Story 11.11: an admin tests the config of one slot of an event alone. */
final class AdminEventSlotYamlTestTest extends FunctionalTestCase
{
    private string $eventId;
    private Game $game;
    private User $admin;
    private Registration $registration;
    private string $slotId;

    protected function setUp(): void
    {
        parent::setUp();
        $now = new \DateTimeImmutable();
        $this->eventId = $this->createEvent('ArchiLAN 4', $now->modify('-1 day'), $now->modify('+1 day'))->getId();
        $this->game = $this->createGame('Lego Star Wars', 'lego-star-wars');
        $this->game->configureApworld('lsw.apworld', 'hash-lsw', 'Lego Star Wars: The Complete Saga', "name: Player\ngame: Lego Star Wars: The Complete Saga\n", new \DateTimeImmutable('2026-07-16'));
        $this->admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $player = $this->createUser('ludo@example.org', ['ROLE_USER'], 'Ludo');
        $this->registration = $this->createRegistration($this->eventId, $player->getId(), Registration::STATUS_RESERVED, [$this->game->getId()]);
        $this->entityManager->flush();

        $slot = $this->registration->getGameSlots()[0] ?? null;
        self::assertIsArray($slot);
        $this->slotId = $slot['slotId'];
    }

    protected function tearDown(): void
    {
        NullRunnerGateway::reset();
        parent::tearDown();
    }

    public function testTheSavedYamlIsTestedAgainstTheServedApworld(): void
    {
        $this->registration->submitSlotPlayerYaml($this->slotId, "name: Ludo\ngame: Lego Star Wars: The Complete Saga\n", 'hash-older', new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->loginAs($this->admin);

        $this->client->jsonRequest('POST', $this->url());

        self::assertResponseStatusCodeSame(202);
        self::assertSame('null-preflight-job', $this->data()['jobId'] ?? null);
        // What the session's generation reads: the registration's YAML, against the apworld the game serves.
        self::assertSame(['playerYaml' => "name: Ludo\ngame: Lego Star Wars: The Complete Saga\n", 'apworldHash' => 'hash-lsw', 'requiredFileIds' => []], NullRunnerGateway::$lastSlotPreflight);
    }

    public function testASlotWithoutASavedYamlIsTestedWithTheDefaultOne(): void
    {
        $this->loginAs($this->admin);

        $this->client->jsonRequest('POST', $this->url());

        self::assertResponseStatusCodeSame(202);
        self::assertSame("name: Player\ngame: Lego Star Wars: The Complete Saga\n", NullRunnerGateway::$lastSlotPreflight['playerYaml'] ?? null);
    }

    public function testAGameThatReadsAFileAsksForIt(): void
    {
        $this->game->markRequiredAtGeneration('LSWSettings.rom_file', 'LSW.iso', '.iso', null, []);
        $this->entityManager->flush();
        $this->loginAs($this->admin);

        $this->client->jsonRequest('POST', $this->url());
        self::assertResponseStatusCodeSame(409);
        $error = $this->decodedJsonResponse()['error'] ?? null;
        self::assertIsArray($error);
        self::assertIsArray($error['details'] ?? null);
        $files = $error['details']['requiredFiles'] ?? null;
        self::assertIsArray($files);
        self::assertIsArray($files[0] ?? null);
        self::assertSame('LSWSettings.rom_file', $files[0]['setting'] ?? null);
        self::assertNull(NullRunnerGateway::$lastSlotPreflight);

        $this->client->request('POST', $this->url(), [], ['requiredFiles' => ['LSWSettings.rom_file' => $this->rom()]]);
        self::assertResponseStatusCodeSame(202);
        self::assertIsArray(NullRunnerGateway::$lastSlotPreflight);
        self::assertSame(['staged-1'], NullRunnerGateway::$lastSlotPreflight['requiredFileIds']);
    }

    public function testARunnerThatDoesNotTakeTheTestGivesTheFileBack(): void
    {
        NullRunnerGateway::$slotPreflightUnavailable = true;
        $this->loginAs($this->admin);

        $this->client->request('POST', $this->url(), [], ['requiredFiles' => ['LSWSettings.rom_file' => $this->rom()]]);

        self::assertResponseStatusCodeSame(503);
        self::assertSame(['staged-1'], NullRunnerGateway::$releasedFileIds);
    }

    public function testASlotOutsideTheEventIsNotFound(): void
    {
        $this->loginAs($this->admin);
        $otherEvent = $this->createEvent('Autre', new \DateTimeImmutable('-1 day'), new \DateTimeImmutable('+1 day'))->getId();

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/events/%s/registrations/%s/slots/%s/yaml-test', $otherEvent, $this->registration->getId(), $this->slotId));
        self::assertResponseStatusCodeSame(404);
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/events/%s/registrations/%s/slots/unknown/yaml-test', $this->eventId, $this->registration->getId()));
        self::assertResponseStatusCodeSame(404);
        self::assertNull(NullRunnerGateway::$lastSlotPreflight);
    }

    public function testTheResultIsReadWithItsErrorSummarised(): void
    {
        NullRunnerGateway::$slotPreflightResult = ['status' => 'failed', 'error' => "Traceback (most recent call last):\nKeyError: 'Could not find option \"1\" for \"DeathLinkStudLoss\"'"];
        $this->loginAs($this->admin);

        $this->client->request('GET', sprintf('/api/v1/admin/events/%s/yaml-tests/null-preflight-job', $this->eventId));
        self::assertResponseStatusCodeSame(200);
        self::assertSame('failed', $this->data()['status'] ?? null);
        self::assertStringContainsString('DeathLinkStudLoss', (string) json_encode($this->data()['error'] ?? null));

        NullRunnerGateway::$slotPreflightResult = null;
        $this->client->request('GET', sprintf('/api/v1/admin/events/%s/yaml-tests/gone', $this->eventId));
        self::assertResponseStatusCodeSame(404);
    }

    public function testOnlyAnAdminTests(): void
    {
        $this->loginAs($this->createUser('player@example.org'));

        $this->client->jsonRequest('POST', $this->url());
        self::assertResponseStatusCodeSame(403);
        $this->client->request('GET', sprintf('/api/v1/admin/events/%s/yaml-tests/null-preflight-job', $this->eventId));
        self::assertResponseStatusCodeSame(403);
        self::assertNull(NullRunnerGateway::$lastSlotPreflight);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }

    private function url(): string
    {
        return sprintf('/api/v1/admin/events/%s/registrations/%s/slots/%s/yaml-test', $this->eventId, $this->registration->getId(), $this->slotId);
    }

    private function rom(): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'rom');
        self::assertIsString($path);
        file_put_contents($path, 'rom bytes');

        return new UploadedFile($path, 'lsw.iso', 'application/octet-stream', null, true);
    }
}
