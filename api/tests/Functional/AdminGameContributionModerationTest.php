<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\GameSelection\Domain\Game;
use App\GameSelection\Domain\GameTutorialContribution;

final class AdminGameContributionModerationTest extends FunctionalTestCase
{
    /** @var list<array{type: string, title: string, description: string, links: list<array{label: string, url: string|null}>}> */
    private const PROPOSED = [['type' => 'apworld', 'title' => 'Nouvelle étape', 'description' => 'd', 'links' => []]];

    public function testApproveAppliesStepsToGameAndMarksApproved(): void
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $game->setInstallSteps([['type' => 'note', 'title' => 'Ancienne', 'description' => '', 'links' => []]]);
        $contribution = GameTutorialContribution::submitForGame(
            bin2hex(random_bytes(16)),
            $this->createUser('author@example.org', ['ROLE_USER'])->getId(),
            $game->getId(),
            self::PROPOSED,
            'Améliore l\'étape',
            new \DateTimeImmutable('2026-06-19T10:00:00+00:00'),
        );
        $this->entityManager->persist($contribution);
        $this->entityManager->flush();
        $contributionId = $contribution->getId();
        $gameId = $game->getId();

        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));

        // The queue exposes the contribution with current + proposed steps for the diff.
        $this->client->jsonRequest('GET', '/api/v1/admin/game-contributions');
        self::assertResponseStatusCodeSame(200);
        $list = $this->decodedJsonResponse()['data'];
        self::assertIsArray($list);
        self::assertCount(1, $list);
        $row = $list[0];
        self::assertIsArray($row);
        self::assertSame('Hollow Knight', $row['target']);
        self::assertIsArray($row['currentSteps']);
        self::assertCount(1, $row['currentSteps']);
        self::assertIsArray($row['proposedSteps']);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/game-contributions/%s/approve', $contributionId));
        self::assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Game::class, $gameId);
        self::assertInstanceOf(Game::class, $reloaded);
        $steps = $reloaded->getInstallSteps();
        self::assertCount(1, $steps);
        self::assertSame('Nouvelle étape', $steps[0]['title']);

        // Re-approving is a conflict.
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/game-contributions/%s/approve', $contributionId));
        self::assertResponseStatusCodeSame(409);
    }

    public function testApproveAppliesModeratorEditedSteps(): void
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $contribution = GameTutorialContribution::submitForGame(
            bin2hex(random_bytes(16)),
            $this->createUser('author@example.org', ['ROLE_USER'])->getId(),
            $game->getId(),
            self::PROPOSED,
            null,
            new \DateTimeImmutable('2026-06-19T10:00:00+00:00'),
        );
        $this->entityManager->persist($contribution);
        $this->entityManager->flush();
        $gameId = $game->getId();

        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/game-contributions/%s/approve', $contribution->getId()), [
            'steps' => [['type' => 'connect', 'title' => 'Version éditée par le modérateur', 'description' => '', 'links' => []]],
        ]);
        self::assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(Game::class, $gameId);
        self::assertInstanceOf(Game::class, $reloaded);
        $steps = $reloaded->getInstallSteps();
        self::assertCount(1, $steps);
        self::assertSame('connect', $steps[0]['type']);
        self::assertSame('Version éditée par le modérateur', $steps[0]['title']);
    }

    public function testApproveProposedNameDoesNotCreateGame(): void
    {
        $contribution = GameTutorialContribution::submitForProposedName(
            bin2hex(random_bytes(16)),
            $this->createUser('author@example.org', ['ROLE_USER'])->getId(),
            'Jeu non listé',
            self::PROPOSED,
            null,
            new \DateTimeImmutable('2026-06-19T10:00:00+00:00'),
        );
        $this->entityManager->persist($contribution);
        $this->entityManager->flush();
        $id = $contribution->getId();

        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/game-contributions/%s/approve', $id));
        self::assertResponseStatusCodeSame(200);

        $this->entityManager->clear();
        $reloaded = $this->entityManager->find(GameTutorialContribution::class, $id);
        self::assertInstanceOf(GameTutorialContribution::class, $reloaded);
        self::assertSame(GameTutorialContribution::STATUS_APPROVED, $reloaded->getStatus());
    }

    public function testRejectRequiresReason(): void
    {
        $id = $this->persistPendingForGame();
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/game-contributions/%s/reject', $id), ['reason' => '  ']);
        self::assertResponseStatusCodeSame(422);

        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/game-contributions/%s/reject', $id), ['reason' => 'Hors sujet']);
        self::assertResponseStatusCodeSame(200);
    }

    public function testUnknownContributionIs404(): void
    {
        $this->loginAs($this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']));
        $this->client->jsonRequest('POST', '/api/v1/admin/game-contributions/missing/approve');
        self::assertResponseStatusCodeSame(404);
    }

    public function testEndpointsRequireAdmin(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/admin/game-contributions');
        self::assertResponseStatusCodeSame(401);

        $this->loginAs($this->createUser('user@example.org', ['ROLE_USER']));
        $this->client->jsonRequest('GET', '/api/v1/admin/game-contributions');
        self::assertResponseStatusCodeSame(403);
    }

    public function testFiltersStatusTargetAndSearch(): void
    {
        $alice = $this->createUser('alice@example.org', ['ROLE_USER'], 'Alice Author');
        $bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob Builder');
        $admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN']);
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $base = new \DateTimeImmutable('2026-06-19T10:00:00+00:00');

        $listedPending = GameTutorialContribution::submitForGame(bin2hex(random_bytes(16)), $alice->getId(), $game->getId(), self::PROPOSED, 'super tuto', $base);
        $unlistedPending = GameTutorialContribution::submitForProposedName(bin2hex(random_bytes(16)), $bob->getId(), 'Jeu Inconnu', self::PROPOSED, 'propose un jeu', $base->modify('+1 minute'));
        $listedApproved = GameTutorialContribution::submitForGame(bin2hex(random_bytes(16)), $alice->getId(), $game->getId(), self::PROPOSED, null, $base->modify('+2 minutes'));
        $listedApproved->approve($admin->getId(), $base->modify('+3 minutes'));
        $unlistedRejected = GameTutorialContribution::submitForProposedName(bin2hex(random_bytes(16)), $bob->getId(), 'Autre Jeu', self::PROPOSED, null, $base->modify('+4 minutes'));
        $unlistedRejected->reject($admin->getId(), 'hors sujet', $base->modify('+5 minutes'));

        foreach ([$listedPending, $unlistedPending, $listedApproved, $unlistedRejected] as $entity) {
            $this->entityManager->persist($entity);
        }
        $this->entityManager->flush();

        $this->loginAs($admin);

        // Default: pending only, badge count = pending, newest first.
        $this->client->jsonRequest('GET', '/api/v1/admin/game-contributions');
        self::assertResponseIsSuccessful();
        self::assertSame([$unlistedPending->getId(), $listedPending->getId()], $this->ids());
        self::assertSame(2, $this->pendingCount());

        // Oldest first flips the order.
        $this->client->jsonRequest('GET', '/api/v1/admin/game-contributions?sort=oldest');
        self::assertSame([$listedPending->getId(), $unlistedPending->getId()], $this->ids());

        // Status buckets; the badge stays the pending count.
        $this->client->jsonRequest('GET', '/api/v1/admin/game-contributions?status=approved');
        self::assertSame([$listedApproved->getId()], $this->ids());
        self::assertSame(2, $this->pendingCount());

        $this->client->jsonRequest('GET', '/api/v1/admin/game-contributions?status=rejected');
        self::assertSame([$unlistedRejected->getId()], $this->ids());

        $this->client->jsonRequest('GET', '/api/v1/admin/game-contributions?status=all');
        self::assertCount(4, $this->ids());

        // Target filter.
        $this->client->jsonRequest('GET', '/api/v1/admin/game-contributions?status=all&target=unlisted&sort=oldest');
        self::assertSame([$unlistedPending->getId(), $unlistedRejected->getId()], $this->ids());

        $this->client->jsonRequest('GET', '/api/v1/admin/game-contributions?status=all&target=listed&sort=oldest');
        self::assertSame([$listedPending->getId(), $listedApproved->getId()], $this->ids());

        // Search on proposed name...
        $this->client->jsonRequest('GET', '/api/v1/admin/game-contributions?status=all&q=Inconnu');
        self::assertSame([$unlistedPending->getId()], $this->ids());

        // ...game name...
        $this->client->jsonRequest('GET', '/api/v1/admin/game-contributions?status=all&q=Hollow&sort=oldest');
        self::assertSame([$listedPending->getId(), $listedApproved->getId()], $this->ids());

        // ...author display name...
        $this->client->jsonRequest('GET', '/api/v1/admin/game-contributions?status=all&q=Alice&sort=oldest');
        self::assertSame([$listedPending->getId(), $listedApproved->getId()], $this->ids());

        // ...and the message.
        $this->client->jsonRequest('GET', '/api/v1/admin/game-contributions?status=all&q=super');
        self::assertSame([$listedPending->getId()], $this->ids());
    }

    /**
     * @return list<string>
     */
    private function ids(): array
    {
        $data = $this->decodedJsonResponse()['data'] ?? null;
        self::assertIsArray($data);
        $ids = [];
        foreach ($data as $row) {
            self::assertIsArray($row);
            $id = $row['id'] ?? null;
            self::assertIsString($id);
            $ids[] = $id;
        }

        return $ids;
    }

    private function pendingCount(): int
    {
        $meta = $this->decodedJsonResponse()['meta'] ?? null;
        self::assertIsArray($meta);
        $count = $meta['count'] ?? null;
        self::assertIsInt($count);

        return $count;
    }

    private function persistPendingForGame(): string
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $contribution = GameTutorialContribution::submitForGame(
            bin2hex(random_bytes(16)),
            $this->createUser('author@example.org', ['ROLE_USER'])->getId(),
            $game->getId(),
            self::PROPOSED,
            null,
            new \DateTimeImmutable('2026-06-19T10:00:00+00:00'),
        );
        $this->entityManager->persist($contribution);
        $this->entityManager->flush();

        return $contribution->getId();
    }
}
