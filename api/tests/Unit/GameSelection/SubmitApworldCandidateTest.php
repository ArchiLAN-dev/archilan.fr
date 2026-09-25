<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Command\SubmitApworldCandidate;
use App\GameSelection\Domain\Entity\ApworldCandidate;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Enum\ApworldCandidateOrigin;
use App\GameSelection\Domain\Enum\ApworldCandidateStatus;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

final class SubmitApworldCandidateTest extends TestCase
{
    private Game $game;
    private InMemoryApworldCandidateRepository $candidates;
    private SpyMinioStorage $minio;
    private MockClock $clock;

    protected function setUp(): void
    {
        $this->game = Game::create('Crystal Project', 'crystal-project', 'A game.', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());
        $this->game->configureApworld('hash-old.apworld', 'hash-old', 'Crystal Project', "game: Crystal Project\n", new \DateTimeImmutable('2026-07-16'));
        $this->candidates = new InMemoryApworldCandidateRepository();
        $this->minio = new SpyMinioStorage();
        $this->clock = new MockClock('2026-09-25 04:10:00+00:00');
    }

    public function testUploadsStoresAndCreatesACandidateWithoutSwitchingTheGame(): void
    {
        $submission = $this->submitter()->submit($this->game->getId(), 'apworld-bytes', 'crystal_project.apworld', 'CrystalProject-v0.18.2', ApworldCandidateOrigin::Manual, 'admin-1');

        self::assertTrue($submission->gameFound);
        self::assertSame([], $submission->errors);
        $candidate = $this->candidates->findById((string) $submission->candidateId);
        self::assertInstanceOf(ApworldCandidate::class, $candidate);
        self::assertSame(ApworldCandidateStatus::Testing, $candidate->getStatus());
        self::assertSame('hash-new', $candidate->getApworldHash());
        self::assertSame('hash-new.apworld', $candidate->getStorageKey());
        self::assertSame('hash-new.apworld', $candidate->getMinioKey());
        self::assertSame("game: Crystal Project\n", $candidate->getDefaultYaml());
        self::assertSame('CrystalProject-v0.18.2', $candidate->getVersionTag());
        self::assertSame('admin-1', $candidate->getSubmittedBy());
        self::assertEquals($this->clock->now(), $candidate->getSubmittedAt());
        self::assertSame('apworld-bytes', $this->minio->objects['apworlds/hash-new.apworld'] ?? null);
        self::assertSame('hash-old', $this->game->getApworldHash(), 'the game keeps serving its apworld until promotion');
        self::assertSame(1, $this->candidates->flushes);
    }

    public function testANewSubmissionSupersedesTheCandidateInTest(): void
    {
        $first = $this->submitter()->submit($this->game->getId(), 'bytes-1', 'a.apworld', 'v1', ApworldCandidateOrigin::Auto, null);
        $second = $this->submitter()->submit($this->game->getId(), 'bytes-2', 'a.apworld', 'v2', ApworldCandidateOrigin::Manual, 'admin-1');

        self::assertSame(ApworldCandidateStatus::Superseded, $this->candidates->findById((string) $first->candidateId)?->getStatus());
        self::assertSame(ApworldCandidateStatus::Testing, $this->candidates->findById((string) $second->candidateId)?->getStatus());
    }

    public function testARunnerRefusalIsReportedAndNothingIsCreated(): void
    {
        $submission = $this->submitter(['error' => 'template_failed', 'detail' => 'ModuleNotFoundError: No module named worlds.foo'])
            ->submit($this->game->getId(), 'bytes', 'a.apworld', null, ApworldCandidateOrigin::Manual, 'admin-1');

        self::assertNull($submission->candidateId);
        self::assertSame(['ArchipelagoGenerate a échoué : ModuleNotFoundError: No module named worlds.foo'], $submission->errors);
        self::assertSame([], $this->candidates->all());
        self::assertSame([], $this->minio->objects);
    }

    public function testAFileThatIsNotAnApworldIsRefusedBeforeAnyUpload(): void
    {
        $submission = $this->submitter()->submit($this->game->getId(), 'bytes', 'crystal_project.zip', null, ApworldCandidateOrigin::Manual, 'admin-1');

        self::assertSame(['Le fichier doit avoir l\'extension .apworld.'], $submission->errors);
        self::assertSame([], $this->candidates->all());
    }

    public function testAStorageFailureIsReportedAndNothingIsCreated(): void
    {
        $this->minio = new SpyMinioStorage(failing: true);

        $submission = $this->submitter()->submit($this->game->getId(), 'bytes', 'a.apworld', null, ApworldCandidateOrigin::Manual, 'admin-1');

        self::assertSame(['storage_unavailable'], $submission->errors);
        self::assertSame([], $this->candidates->all());
    }

    public function testAnUnknownGameIsReported(): void
    {
        $submission = $this->submitter()->submit('unknown', 'bytes', 'a.apworld', null, ApworldCandidateOrigin::Manual, 'admin-1');

        self::assertFalse($submission->gameFound);
        self::assertNull($submission->candidateId);
    }

    /**
     * @param array<string, mixed>|null $uploadResult
     */
    private function submitter(?array $uploadResult = null): SubmitApworldCandidate
    {
        $games = self::createStub(GameRepositoryInterface::class);
        $games->method('findById')->willReturnCallback(fn (string $id): ?Game => $id === $this->game->getId() ? $this->game : null);

        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('uploadApworld')->willReturn($uploadResult ?? [
            'storageKey' => 'hash-new.apworld',
            'hash' => 'hash-new',
            'archipelagoGameName' => 'Crystal Project',
            'defaultYaml' => "game: Crystal Project\n",
            'optionTypes' => [],
            'locationNames' => [],
        ]);

        return new SubmitApworldCandidate($games, $this->candidates, $runner, $this->minio, $this->clock, new NullLogger(), 'apworlds');
    }
}
