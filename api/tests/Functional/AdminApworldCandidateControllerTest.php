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

    /** Story 38.14: a candidate held for approval, tested, put online by an admin. */
    public function testApprovingPutsTheAwaitingCandidateOnlineAsValidated(): void
    {
        $candidate = $this->candidate(hold: true);
        $candidate->awaitApproval(new \DateTimeImmutable('2026-09-25 04:15:00+00:00'));
        $this->entityManager->persist($candidate);
        $this->entityManager->flush();
        $this->loginAs($this->admin);

        $this->client->request('POST', sprintf('/api/v1/admin/games/%s/apworld-candidate/approve', $this->game->getId()));

        self::assertResponseStatusCodeSame(200);
        $this->entityManager->clear();
        self::assertSame('hash-new', $this->entityManager->find(Game::class, $this->game->getId())?->getApworldHash());
        $promoted = $this->entityManager->find(ApworldCandidate::class, 'candidate-1');
        self::assertSame(ApworldCandidateStatus::Promoted, $promoted?->getStatus());
        self::assertSame($this->admin->getId(), $promoted->getApprovedBy());
        self::assertNull($promoted->getForcedBy());
        self::assertNull(NullRunnerGateway::$apworldPreflights['hash-new']['overridden'] ?? null, 'a passed test needs no override');

        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $sent = array_map(static fn ($envelope): object => $envelope->getMessage(), $transport->getSent());
        self::assertContainsEquals(new PostApworldPromotionToStaffChannelJob('candidate-1', null), $sent);
        self::assertContainsEquals(new ApworldPromoted($this->game->getId(), 'hash-old', 'hash-new', "old\n"), $sent);
    }

    public function testApprovingACandidateStillInTestReturns409(): void
    {
        $this->entityManager->persist($this->candidate(hold: true));
        $this->entityManager->flush();
        $this->loginAs($this->admin);

        $this->client->request('POST', sprintf('/api/v1/admin/games/%s/apworld-candidate/approve', $this->game->getId()));

        self::assertResponseStatusCodeSame(409);
    }

    public function testTheGamePageShowsAnAwaitingCandidate(): void
    {
        $candidate = $this->candidate(hold: true);
        $candidate->awaitApproval(new \DateTimeImmutable('2026-09-25 04:15:00+00:00'));
        $this->entityManager->persist($candidate);
        $this->entityManager->flush();
        $this->loginAs($this->admin);

        $this->client->request('GET', sprintf('/api/v1/admin/games/%s', $this->game->getId()));

        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $payload = $data['apworldCandidate'] ?? null;
        self::assertIsArray($payload);
        self::assertSame('awaiting', $payload['status']);
        self::assertTrue($payload['heldForApproval']);
    }

    /** Story 38.14: a pasted YAML is generated against the candidate version, nothing goes online. */
    public function testAYamlTestRunsOnTheCandidateVersion(): void
    {
        $this->entityManager->persist($this->candidate());
        $this->entityManager->flush();
        $this->loginAs($this->admin);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/games/%s/apworld-candidate/test-yaml', $this->game->getId()), ['yaml' => "name: Jean\ngame: Crystal Project\n"]);

        self::assertResponseStatusCodeSame(202);
        self::assertSame('null-preflight-job', $this->data()['jobId'] ?? null);
        self::assertSame(['playerYaml' => "name: Jean\ngame: Crystal Project\n", 'apworldHash' => 'hash-new'], NullRunnerGateway::$lastSlotPreflight);
        $this->entityManager->clear();
        self::assertSame('hash-old', $this->entityManager->find(Game::class, $this->game->getId())?->getApworldHash());
        self::assertSame(ApworldCandidateStatus::Testing, $this->entityManager->find(ApworldCandidate::class, 'candidate-1')?->getStatus());
    }

    public function testAYamlTestResultIsReadWithItsErrorSummarised(): void
    {
        NullRunnerGateway::$slotPreflightResult = ['status' => 'failed', 'error' => "Traceback (most recent call last):\nAttributeError: 'MultiWorld' object has no attribute 'architect'"];
        $this->loginAs($this->admin);

        $this->client->request('GET', sprintf('/api/v1/admin/games/%s/apworld-candidate/test-yaml/null-preflight-job', $this->game->getId()));

        self::assertResponseStatusCodeSame(200);
        self::assertSame('failed', $this->data()['status'] ?? null);
        self::assertStringContainsString('architect', (string) json_encode($this->data()['error'] ?? null));
    }

    public function testAnUnknownYamlTestReturns404(): void
    {
        NullRunnerGateway::$slotPreflightResult = null;
        $this->loginAs($this->admin);

        $this->client->request('GET', sprintf('/api/v1/admin/games/%s/apworld-candidate/test-yaml/gone', $this->game->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    public function testAYamlTestNeedsAYamlACandidateAndARunner(): void
    {
        $this->loginAs($this->admin);
        $uri = sprintf('/api/v1/admin/games/%s/apworld-candidate/test-yaml', $this->game->getId());

        $this->client->jsonRequest('POST', $uri, ['yaml' => "name: Jean\n"]);
        self::assertResponseStatusCodeSame(404, 'no candidate');

        $this->entityManager->persist($this->candidate());
        $this->entityManager->flush();
        $this->client->jsonRequest('POST', $uri, ['yaml' => '   ']);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('POST', $uri, ['yaml' => str_repeat('a', 100 * 1024 + 1)]);
        self::assertResponseStatusCodeSame(422);

        NullRunnerGateway::$slotPreflightUnavailable = true;
        $this->client->jsonRequest('POST', $uri, ['yaml' => "name: Jean\n"]);
        self::assertResponseStatusCodeSame(503);
    }

    public function testYamlTestsAreForAdminsOnly(): void
    {
        $this->loginAs($this->createUser('player@example.org'));

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/games/%s/apworld-candidate/test-yaml', $this->game->getId()), ['yaml' => "name: x\n"]);

        self::assertResponseStatusCodeSame(403);
    }

    /**
     * @return array<mixed>
     */
    private function data(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);

        return $data;
    }

    private function rejectedCandidate(): void
    {
        $candidate = $this->candidate();
        $candidate->reject('Fill.FillError: boom', new \DateTimeImmutable('2026-09-25 04:20:00+00:00'));
        $this->entityManager->persist($candidate);
        $this->entityManager->flush();
    }

    private function candidate(bool $hold = false): ApworldCandidate
    {
        return ApworldCandidate::submit('candidate-1', $this->game->getId(), 'hash-new', 'hash-new.apworld', 'hash-new.apworld', "new\n", 'Crystal Project', null, ApworldCandidateOrigin::Manual, $this->admin->getId(), new \DateTimeImmutable('2026-09-25 04:10:00+00:00'), holdForApproval: $hold);
    }
}
