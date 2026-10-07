<?php

declare(strict_types=1);

namespace App\Tests\Unit\CatalogSync;

use App\CatalogSync\Application\Handler\SubmitAutoApworldUpdateJobHandler;
use App\CatalogSync\Application\Message\SubmitAutoApworldUpdateJob;
use App\CatalogSync\Application\Service\ApworldVersionChecker;
use App\GameSelection\Application\Command\SubmitApworldCandidate;
use App\GameSelection\Domain\Entity\ApworldCandidate;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Enum\ApworldCandidateOrigin;
use App\GameSelection\Domain\Enum\ApworldCandidateStatus;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Sessions\Application\Port\RunnerGatewayInterface;
use App\Tests\Unit\GameSelection\InMemoryApworldCandidateRepository;
use App\Tests\Unit\GameSelection\SpyMinioStorage;
use App\Tests\Unit\GameSelection\WarningCollectingLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class SubmitAutoApworldUpdateJobHandlerTest extends TestCase
{
    private Game $game;
    private InMemoryApworldCandidateRepository $candidates;
    private WarningCollectingLogger $logger;

    protected function setUp(): void
    {
        $this->game = Game::create('Crystal Project', 'crystal-project', 'A game.', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());
        $this->candidates = new InMemoryApworldCandidateRepository();
        $this->logger = new WarningCollectingLogger();
    }

    public function testDownloadsTheReleaseAndSubmitsAnAutomaticCandidate(): void
    {
        $this->handler(new MockResponse('apworld-bytes'))($this->job());

        $candidate = $this->candidates->findPendingForGame($this->game->getId());
        self::assertSame(ApworldCandidateOrigin::Auto, $candidate?->getOrigin());
        self::assertSame('CrystalProject-v0.18.2', $candidate->getVersionTag());
        self::assertNull($candidate->getSubmittedBy());
    }

    public function testNeverSupersedesACandidateAnAdminSubmittedMeanwhile(): void
    {
        $manual = ApworldCandidate::submit('manual-1', $this->game->getId(), 'hash-manual', 'hash-manual.apworld', 'hash-manual.apworld', "x\n", 'Crystal Project', null, ApworldCandidateOrigin::Manual, 'admin-1', new \DateTimeImmutable());
        $this->candidates->save($manual);

        $this->handler(new MockResponse('apworld-bytes'))($this->job());

        self::assertSame(ApworldCandidateStatus::Testing, $manual->getStatus());
        self::assertCount(1, $this->candidates->all());
    }

    public function testADownloadFailureIsLoggedAndSubmitsNothing(): void
    {
        $this->handler(new MockResponse('', ['http_code' => 404]))($this->job());

        self::assertSame([], $this->candidates->all());
        self::assertSame(['catalog_sync.auto_update_failed'], $this->logger->warnings);
    }

    private function job(): SubmitAutoApworldUpdateJob
    {
        return new SubmitAutoApworldUpdateJob(
            $this->game->getId(),
            'CrystalProject-v0.18.2',
            'https://github.com/Emerassi/CrystalProjectAPWorld/releases/download/CrystalProject-v0.18.2/crystal_project.apworld',
            'crystal_project.apworld',
        );
    }

    private function handler(MockResponse $download): SubmitAutoApworldUpdateJobHandler
    {
        $games = self::createStub(GameRepositoryInterface::class);
        $games->method('findById')->willReturn($this->game);
        $runner = self::createStub(RunnerGatewayInterface::class);
        $runner->method('uploadApworld')->willReturn([
            'storageKey' => 'hash-new.apworld',
            'hash' => 'hash-new',
            'archipelagoGameName' => 'Crystal Project',
            'defaultYaml' => "game: Crystal Project\n",
        ]);

        return new SubmitAutoApworldUpdateJobHandler(
            new ApworldVersionChecker(new MockHttpClient($download), new NullLogger(), 'ghp_test_token'),
            new SubmitApworldCandidate($games, $this->candidates, $runner, new SpyMinioStorage(), new MockClock(), new NullLogger(), 'apworlds'),
            $this->candidates,
            $this->logger,
        );
    }
}
