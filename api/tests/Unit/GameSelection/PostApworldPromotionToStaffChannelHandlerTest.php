<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\Handler\PostApworldPromotionToStaffChannelHandler;
use App\GameSelection\Application\Message\PostApworldPromotionToStaffChannelJob;
use App\GameSelection\Application\Support\StaffAlertFactory;
use App\GameSelection\Domain\Entity\ApworldCandidate;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Enum\ApworldCandidateOrigin;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use PHPUnit\Framework\TestCase;

final class PostApworldPromotionToStaffChannelHandlerTest extends TestCase
{
    private InMemoryApworldCandidateRepository $candidates;
    private Game $game;
    private WarningCollectingLogger $logger;

    protected function setUp(): void
    {
        $this->candidates = new InMemoryApworldCandidateRepository();
        $this->game = Game::create('Crystal Project', 'crystal-project', 'A game.', null, 'alt', '', Game::AVAILABILITY_AVAILABLE, new \DateTimeImmutable());
        $this->game->updateCatalogueMetadata(sourceUrl: 'https://github.com/Emerassi/CrystalProjectAPWorld', deployedVersion: 'CrystalProject-v0.18.2');
        $this->game->recordApworldCheck('CrystalProject-v0.18.2', new \DateTimeImmutable(), 'https://github.com/Emerassi/CrystalProjectAPWorld/releases/tag/CrystalProject-v0.18.2');
        $this->logger = new WarningCollectingLogger();
    }

    public function testAnnouncesAnAutomaticPromotionWithBothVersionsAndTheRelease(): void
    {
        $this->promotedCandidate(ApworldCandidateOrigin::Auto, 'CrystalProject-v0.18.2', null);
        $channel = new SpyStaffAlertChannel();

        $this->handler($channel)(new PostApworldPromotionToStaffChannelJob('candidate-1', 'CrystalProject-v0.17.0'));

        self::assertCount(1, $channel->posted);
        self::assertSame('Crystal Project mis à jour : CrystalProject-v0.17.0 → CrystalProject-v0.18.2', $channel->posted[0]->title);
        self::assertStringContainsString('Mise à jour automatique', $channel->posted[0]->description);
        self::assertSame('https://github.com/Emerassi/CrystalProjectAPWorld/releases/tag/CrystalProject-v0.18.2', $channel->posted[0]->url);
    }

    public function testAForcedPromotionNamesTheAdmin(): void
    {
        $this->promotedCandidate(ApworldCandidateOrigin::Manual, null, 'admin-1');
        $channel = new SpyStaffAlertChannel();

        $this->handler($channel)(new PostApworldPromotionToStaffChannelJob('candidate-1', null));

        self::assertStringContainsString('Forcée par Jean malgré le test', $channel->posted[0]->description);
    }

    public function testAnUnknownCandidatePostsNothing(): void
    {
        $channel = new SpyStaffAlertChannel();

        $this->handler($channel)(new PostApworldPromotionToStaffChannelJob('unknown', null));

        self::assertSame([], $channel->posted);
    }

    public function testAChannelFailureIsLoggedAndSwallowed(): void
    {
        $this->promotedCandidate(ApworldCandidateOrigin::Auto, 'v2', null);

        $this->handler(new SpyStaffAlertChannel(failing: true))(new PostApworldPromotionToStaffChannelJob('candidate-1', 'v1'));

        self::assertSame(['apworld_candidates.promotion_not_posted'], $this->logger->warnings);
    }

    private function promotedCandidate(ApworldCandidateOrigin $origin, ?string $tag, ?string $forcedBy): void
    {
        $candidate = ApworldCandidate::submit('candidate-1', $this->game->getId(), 'hash-new', 'hash-new.apworld', 'hash-new.apworld', "game: x\n", 'Crystal Project', $tag, $origin, null, new \DateTimeImmutable('2026-09-25 04:10:00+00:00'));
        $candidate->promote(new \DateTimeImmutable('2026-09-25 04:20:00+00:00'), $forcedBy);
        $this->candidates->save($candidate);
    }

    private function handler(SpyStaffAlertChannel $channel): PostApworldPromotionToStaffChannelHandler
    {
        $games = self::createStub(GameRepositoryInterface::class);
        $games->method('findById')->willReturn($this->game);
        $users = self::createStub(UserRepositoryInterface::class);
        $users->method('findById')->willReturnCallback(static fn (string $id): ?User => 'admin-1' === $id
            ? new User('admin-1', 'jean@example.com', 'jean@example.com', 'Jean', 'hash', ['ROLE_USER', 'ROLE_ADMIN'], new \DateTimeImmutable(), new \DateTimeImmutable(), new \DateTimeImmutable())
            : null);

        return new PostApworldPromotionToStaffChannelHandler($this->candidates, $games, $users, new StaffAlertFactory('https://archilan.fr'), $channel, $this->logger);
    }
}
