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
