<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Application\Message\RunSlotPreflightJob;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\Sessions\Infrastructure\Double\NullRunnerGateway;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Story 44.7: « Tester ma config » for a game that reads a ROM. The player joins the file; it is handed to the
 * orchestrator during the request and only its one-use id travels in the test job.
 */
final class SlotPreflightWithRequiredFileTest extends FunctionalTestCase
{
    private const string SLOT_ID = 'aaaabbbbccccdddd';
    private const string YAML = "name: Jean\ngame: Donkey Kong Country 3\n";

    protected function setUp(): void
    {
        parent::setUp();
        NullRunnerGateway::reset();
    }

    protected function tearDown(): void
    {
        NullRunnerGateway::reset();
        parent::tearDown();
    }

    public function testThePlayerJoinsTheRomAndOnlyItsIdIsQueued(): void
    {
        [$player, $run] = $this->createRun();
        $this->loginAs($player);

        $this->requestTest($run, ['DK3Settings.rom_file' => $this->upload('ROMDATA', 'dkc3.sfc')]);

        self::assertResponseStatusCodeSame(202);
        self::assertSame([['setting' => 'DK3Settings.rom_file', 'filename' => 'dkc3.sfc', 'content' => 'ROMDATA']], NullRunnerGateway::$stagedFiles);
        self::assertSame(['staged-1'], $this->queuedJob()->requiredFileIds);
    }

    public function testATestWithoutFileQueuesNoId(): void
    {
        [$player, $run] = $this->createRun();
        $this->loginAs($player);

        $this->requestTest($run, []);

        self::assertResponseStatusCodeSame(202);
        self::assertSame([], $this->queuedJob()->requiredFileIds);
    }

    public function testARefusedFileStartsNothing(): void
    {
        NullRunnerGateway::$refuseStagingOf = 'DK3Settings.rom_file';
        [$player, $run] = $this->createRun();
        $this->loginAs($player);

        $this->requestTest($run, ['DK3Settings.rom_file' => $this->upload('ROMDATA', 'dkc3.sfc')]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('required_file_refused', $this->errorCode());
        self::assertSame([], $this->queuedJobs());
    }

    public function testAnUnknownSettingIsRefusedBeforeAnythingIsSent(): void
    {
        [$player, $run] = $this->createRun();
        $this->loginAs($player);

        $this->requestTest($run, ['not a setting' => $this->upload('ROMDATA', 'dkc3.sfc')]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->errorCode());
        self::assertSame([], NullRunnerGateway::$stagedFiles);
    }

    public function testSavingTheYamlOfARomGameRunsNoTestAndForgetsTheOldVerdict(): void
    {
        [$player, $run] = $this->createRun(withVerdict: true);
        $this->loginAs($player);

        $this->client->jsonRequest('PUT', sprintf('/api/v1/runs/%s/participants/me/slots/%s/yaml', $run->getId(), self::SLOT_ID), [
            'playerYaml' => "name: Jean\ngame: Donkey Kong Country 3\nprogression_balancing: 50\n",
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame([], $this->queuedJobs(), 'without the ROM the automatic test could only fail');
        $this->entityManager->clear();
        $seat = $this->entityManager->getRepository(RunParticipant::class)->findOneBy(['runId' => $run->getId(), 'userId' => $player->getId()]);
        self::assertInstanceOf(RunParticipant::class, $seat);
        self::assertNull($seat->getSlot(self::SLOT_ID)['preflight'] ?? null);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** @return array{0: User, 1: Run} */
    private function createRun(bool $withVerdict = false): array
    {
        $player = $this->createUser('alice@example.org');
        $game = $this->createGame('Donkey Kong Country 3', 'dkc3');
        $now = new \DateTimeImmutable('2026-10-16T10:00:00+00:00');
        $game->configureApworld('dkc3.apworld', 'hash-dkc3', 'Donkey Kong Country 3', self::YAML, $now);
        $game->markRequiredAtGeneration('DK3Settings.rom_file', 'dkc3.sfc', '.sfc', 'DKC3 (US) ROM File', []);

        $run = Run::create($player->getId(), 'Test Run', $now);
        $run->configureGames([['gameId' => $game->getId()]], $now);
        $this->entityManager->persist($run);
        $seat = RunParticipant::create($run->getId(), $player->getId(), $now);
        $seat->replaceSlots([['slotId' => self::SLOT_ID, 'gameId' => $game->getId(), 'slotOrder' => 1]]);
        $seat->submitSlotPlayerYaml(self::SLOT_ID, self::YAML, 'hash-dkc3');
        if ($withVerdict) {
            $seat->recordSlotPreflight(self::SLOT_ID, 'failed', 'Ce jeu demande le fichier « DKC3 (US) ROM File »', hash('sha256', self::YAML), $now);
        }
        $this->entityManager->persist($seat);
        $this->entityManager->flush();

        return [$player, $run];
    }

    private function upload(string $content, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'rom');
        self::assertIsString($path);
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'application/octet-stream', null, true);
    }

    /** @param array<string, UploadedFile> $files */
    private function requestTest(Run $run, array $files): void
    {
        $this->client->request('POST', sprintf('/api/v1/runs/%s/participants/me/slots/%s/preflight', $run->getId(), self::SLOT_ID), [], ['requiredFiles' => $files]);
    }

    /** @return list<RunSlotPreflightJob> */
    private function queuedJobs(): array
    {
        $transport = self::getContainer()->get('messenger.transport.run_server');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $jobs = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof RunSlotPreflightJob) {
                $jobs[] = $message;
            }
        }

        return $jobs;
    }

    private function queuedJob(): RunSlotPreflightJob
    {
        $jobs = $this->queuedJobs();
        self::assertCount(1, $jobs);

        return $jobs[0];
    }

    private function errorCode(): string
    {
        $decoded = json_decode((string) $this->client->getResponse()->getContent(), true);
        self::assertIsArray($decoded);
        $error = $decoded['error'] ?? null;
        self::assertIsArray($error);
        $code = $error['code'] ?? null;
        self::assertIsString($code);

        return $code;
    }
}
