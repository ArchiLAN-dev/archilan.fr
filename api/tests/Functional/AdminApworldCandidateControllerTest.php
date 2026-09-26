<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Application\Message\ApworldPromoted;
use App\GameSelection\Application\Message\PostApworldPromotionToStaffChannelJob;
use App\GameSelection\Domain\Entity\ApworldCandidate;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Enum\ApworldCandidateOrigin;
use App\GameSelection\Domain\Enum\ApworldCandidateStatus;
use App\Identity\Domain\Entity\User;
use App\Sessions\Infrastructure\Double\NullRunnerGateway;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Story 38.6 AC 4 and 19: an admin forces a candidate into service, or reruns its test.
 */
final class AdminApworldCandidateControllerTest extends FunctionalTestCase
{
    private User $admin;
    private Game $game;

    protected function setUp(): void
    {
        parent::setUp();
        NullRunnerGateway::reset();

        $this->admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Jean', slug: 'jean');
        $this->game = $this->createGame('Crystal Project', 'crystal-project');
        $this->game->configureApworld('hash-old.apworld', 'hash-old', 'Crystal Project', "old\n", new \DateTimeImmutable('2026-07-16'));
        $this->entityManager->flush();
    }

    protected function tearDown(): void
    {
        NullRunnerGateway::reset();
        parent::tearDown();
    }

    public function testForcingSwitchesTheGameAndQueuesTheAnnouncement(): void
    {
        $this->rejectedCandidate();
        $this->loginAs($this->admin);

        $this->client->request('POST', sprintf('/api/v1/admin/games/%s/apworld-candidate/promote', $this->game->getId()));

        self::assertResponseStatusCodeSame(200);
        $this->entityManager->clear();
        $game = $this->entityManager->find(Game::class, $this->game->getId());
        self::assertSame('hash-new', $game?->getApworldHash());
        $candidate = $this->entityManager->find(ApworldCandidate::class, 'candidate-1');
        self::assertSame(ApworldCandidateStatus::Promoted, $candidate?->getStatus());
        self::assertSame($this->admin->getId(), $candidate->getForcedBy());

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        self::assertEquals(
            [
                new PostApworldPromotionToStaffChannelJob('candidate-1', null),
                new ApworldPromoted($this->game->getId(), 'hash-old', 'hash-new', 'old
'),
            ],
            array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent()),
        );
    }

    public function testRetryPutsTheCandidateBackInTest(): void
    {
        $this->rejectedCandidate();
        $this->loginAs($this->admin);

        $this->client->request('POST', sprintf('/api/v1/admin/games/%s/apworld-candidate/retry', $this->game->getId()));

        self::assertResponseStatusCodeSame(200);
        $this->entityManager->clear();
        self::assertSame(ApworldCandidateStatus::Testing, $this->entityManager->find(ApworldCandidate::class, 'candidate-1')?->getStatus());
    }

    public function testRetryingACandidateStillInTestReturns409(): void
    {
        $this->entityManager->persist($this->candidate());
        $this->entityManager->flush();
        $this->loginAs($this->admin);

        $this->client->request('POST', sprintf('/api/v1/admin/games/%s/apworld-candidate/retry', $this->game->getId()));

        self::assertResponseStatusCodeSame(409);
    }

    public function testAGameWithoutCandidateReturns404(): void
    {
        $this->loginAs($this->admin);

        $this->client->request('POST', sprintf('/api/v1/admin/games/%s/apworld-candidate/promote', $this->game->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testNonAdminIsRefused(): void
    {
        $this->loginAs($this->createUser('player@example.org'));

        $this->client->request('POST', sprintf('/api/v1/admin/games/%s/apworld-candidate/promote', $this->game->getId()));

        self::assertResponseStatusCodeSame(403);
    }

    private function rejectedCandidate(): void
    {
        $candidate = $this->candidate();
        $candidate->reject('Fill.FillError: boom', new \DateTimeImmutable('2026-09-25 04:20:00+00:00'));
        $this->entityManager->persist($candidate);
        $this->entityManager->flush();
    }

    private function candidate(): ApworldCandidate
    {
        return ApworldCandidate::submit('candidate-1', $this->game->getId(), 'hash-new', 'hash-new.apworld', 'hash-new.apworld', "new\n", 'Crystal Project', null, ApworldCandidateOrigin::Manual, $this->admin->getId(), new \DateTimeImmutable('2026-09-25 04:10:00+00:00'));
    }
}
