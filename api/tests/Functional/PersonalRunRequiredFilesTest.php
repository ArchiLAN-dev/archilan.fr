<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Application\Message\LaunchPersonalRunJob;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\Sessions\Infrastructure\Double\NullRunnerGateway;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Story 44.2: the launcher joins the file a world reads at generation (a ROM). It is handed to the
 * orchestrator during the request and only its one-use id travels in the launch job: nothing is stored.
 */
final class PersonalRunRequiredFilesTest extends FunctionalTestCase
{
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

    public function testTheOwnerJoinsAFileAndOnlyItsIdIsQueued(): void
    {
        [$owner, , $run] = $this->createRun();
        $this->loginAs($owner);

        $this->start($run, ['DK3Settings.rom_file' => $this->upload('ROMDATA', 'dkc3.sfc')]);

        self::assertResponseStatusCodeSame(202);
        self::assertSame([['setting' => 'DK3Settings.rom_file', 'filename' => 'dkc3.sfc', 'content' => 'ROMDATA']], NullRunnerGateway::$stagedFiles);
        self::assertSame(['staged-1'], $this->queuedJob()->requiredFileIds);
    }

    public function testAStartWithoutFileQueuesNoId(): void
    {
        [$owner, , $run] = $this->createRun();
        $this->loginAs($owner);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$run->getId().'/start');

        self::assertResponseStatusCodeSame(202);
        self::assertSame([], NullRunnerGateway::$stagedFiles);
        self::assertSame([], $this->queuedJob()->requiredFileIds);
    }

    public function testAParticipantResumingTheRunCannotProvideAFile(): void
    {
        [, $participant, $run] = $this->createRun(idle: true);
        $this->loginAs($participant);

        $this->start($run, ['DK3Settings.rom_file' => $this->upload('ROMDATA', 'dkc3.sfc')]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], NullRunnerGateway::$stagedFiles);
        self::assertSame(Run::STATUS_IDLE, $this->statusOf($run));
    }

    public function testAnUnknownSettingOrTooManyFilesAreRefusedBeforeAnythingIsSent(): void
    {
        [$owner, , $run] = $this->createRun();
        $this->loginAs($owner);

        $this->start($run, ['rom_file' => $this->upload('ROMDATA', 'dkc3.sfc')]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('validation_failed', $this->errorCode());

        $files = [];
        foreach (['A', 'B', 'C', 'D', 'E'] as $group) {
            $files[$group.'Settings.rom_file'] = $this->upload('ROMDATA', 'rom.sfc');
        }
        $this->start($run, $files);
        self::assertResponseStatusCodeSame(422);

        self::assertSame([], NullRunnerGateway::$stagedFiles);
        self::assertSame(Run::STATUS_DRAFT, $this->statusOf($run));
    }

    public function testARefusedFileReleasesTheOthersAndLeavesTheRunAsItWas(): void
    {
        [$owner, , $run] = $this->createRun();
        $this->loginAs($owner);
        NullRunnerGateway::$refuseStagingOf = 'OOTSettings.rom_file';

        $this->start($run, [
            'DK3Settings.rom_file' => $this->upload('ROMDATA', 'dkc3.sfc'),
            'OOTSettings.rom_file' => $this->upload('ROMDATA', 'oot.z64'),
        ]);

        self::assertResponseStatusCodeSame(422);
        self::assertSame('required_file_refused', $this->errorCode());
        self::assertSame(['staged-1'], NullRunnerGateway::$releasedFileIds);
        self::assertSame(Run::STATUS_DRAFT, $this->statusOf($run));
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** @return array{0: User, 1: User, 2: Run} */
    private function createRun(bool $idle = false): array
    {
        $owner = $this->createUser('alice@example.org');
        $participant = $this->createUser('bob@example.org');
        $game = $this->createGame('Donkey Kong Country 3', 'dkc3');
        $now = new \DateTimeImmutable('2026-10-15T10:00:00+00:00');

        $run = Run::create($owner->getId(), 'Test Run', $now);
        $run->configureGames([['gameId' => $game->getId()]], $now);
        $this->entityManager->persist($run);
        $ownerSeat = RunParticipant::create($run->getId(), $owner->getId(), $now);
        $ownerSeat->replaceSlots([['slotId' => bin2hex(random_bytes(8)), 'gameId' => $game->getId()]]);
        $this->entityManager->persist($ownerSeat);
        $this->entityManager->persist(RunParticipant::create($run->getId(), $participant->getId(), $now));
        if ($idle) {
            $run->markStopped($now);
        }
        $this->entityManager->flush();

        return [$owner, $participant, $run];
    }

    private function upload(string $content, string $name): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'rom');
        self::assertIsString($path);
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, 'application/octet-stream', null, true);
    }

    /** @param array<string, UploadedFile> $files */
    private function start(Run $run, array $files): void
    {
        $this->client->request('POST', '/api/v1/runs/'.$run->getId().'/start', [], ['requiredFiles' => $files]);
    }

    private function queuedJob(): LaunchPersonalRunJob
    {
        /** @var InMemoryTransport $transport */
        $transport = self::getContainer()->get('messenger.transport.run_server');
        $jobs = array_values(array_filter($transport->getSent(), static fn ($e) => $e->getMessage() instanceof LaunchPersonalRunJob));
        self::assertCount(1, $jobs);
        $job = $jobs[0]->getMessage();
        self::assertInstanceOf(LaunchPersonalRunJob::class, $job);

        return $job;
    }

    private function statusOf(Run $run): string
    {
        $this->entityManager->clear();
        $fresh = $this->entityManager->find(Run::class, $run->getId());
        self::assertInstanceOf(Run::class, $fresh);

        return $fresh->getStatus();
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
