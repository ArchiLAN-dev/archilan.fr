<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Notification;
use App\GameSelection\Domain\Entity\GameTutorialContribution;
use App\Identity\Domain\Entity\User;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Enum\PelleReason;

/**
 * Story 41.5: an approved tutorial contribution pays its author the pelles the admin chose.
 */
final class ContributionRewardTest extends FunctionalTestCase
{
    /** @var list<array{type: string, title: string, description: string, links: list<array{label: string, url: string|null}>}> */
    private const array PROPOSED = [['type' => 'apworld', 'title' => 'Nouvelle étape', 'description' => 'd', 'links' => []]];

    private User $admin;
    private User $author;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->createUser('admin@example.org', ['ROLE_USER', 'ROLE_ADMIN'], 'Admin');
        $this->author = $this->createUser('author@example.org', ['ROLE_USER'], 'Author');
    }

    public function testTheAuthorIsPaidWhatTheAdminChose(): void
    {
        $id = $this->contribution($this->author);
        $this->loginAs($this->admin);

        $this->approve($id, ['pelles' => 75]);

        self::assertResponseIsSuccessful();
        $rewards = $this->rewards();
        self::assertCount(1, $rewards);
        self::assertSame(75, $rewards[0]->getAmount());
        self::assertSame($this->author->getId(), $rewards[0]->getUserId());
        self::assertSame($this->admin->getId(), $rewards[0]->getAuthorId());
        self::assertSame('Tutoriel validé : Hollow Knight', $rewards[0]->getLabel());
        self::assertCount(1, $this->entityManager->getRepository(Notification::class)->findBy(['recipientId' => $this->author->getId(), 'type' => Notification::TYPE_PELLES_ADJUSTED]));
    }

    public function testNoAmountPaysNothing(): void
    {
        $id = $this->contribution($this->author);
        $this->loginAs($this->admin);

        $this->approve($id, []);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->rewards());
    }

    public function testAnAmountOutOfBoundsRefusesTheApproval(): void
    {
        $id = $this->contribution($this->author);
        $this->loginAs($this->admin);

        $this->approve($id, ['pelles' => 1001]);

        self::assertResponseStatusCodeSame(422);
        $this->entityManager->clear();
        $contribution = $this->entityManager->find(GameTutorialContribution::class, $id);
        self::assertInstanceOf(GameTutorialContribution::class, $contribution);
        self::assertSame(GameTutorialContribution::STATUS_PENDING, $contribution->getStatus());
    }

    public function testAnAdminCannotPayTheirOwnContributionButCanApproveIt(): void
    {
        $id = $this->contribution($this->admin);
        $this->loginAs($this->admin);

        $this->approve($id, ['pelles' => 50]);
        self::assertResponseStatusCodeSame(403);

        $this->approve($id, ['pelles' => 0]);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $this->rewards());
    }

    private function contribution(User $author): string
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $contribution = GameTutorialContribution::submitForGame(
            bin2hex(random_bytes(16)),
            $author->getId(),
            $game->getId(),
            self::PROPOSED,
            null,
            new \DateTimeImmutable('2026-10-01T10:00:00+00:00'),
        );
        $this->entityManager->persist($contribution);
        $this->entityManager->flush();

        return $contribution->getId();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function approve(string $id, array $payload): void
    {
        $this->client->jsonRequest('POST', sprintf('/api/v1/admin/game-contributions/%s/approve', $id), $payload);
    }

    /**
     * @return list<PelleMovement>
     */
    private function rewards(): array
    {
        return $this->entityManager->getRepository(PelleMovement::class)->findBy(['reason' => PelleReason::ContributionReward]);
    }
}
