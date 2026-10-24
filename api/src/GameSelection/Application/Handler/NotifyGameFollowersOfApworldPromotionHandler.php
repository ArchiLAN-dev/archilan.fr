<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Handler;

use App\Community\Application\Support\Notifier;
use App\GameSelection\Application\Message\ApworldPromoted;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Repository\GameFollowRepositoryInterface;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Tells the members who follow a game that the site put a new apworld in place for it (story 38.22): its first one,
 * or a new version.
 */
#[AsMessageHandler]
final readonly class NotifyGameFollowersOfApworldPromotionHandler
{
    public const string NOTIFICATION_TYPE = 'game_apworld_updated';

    public function __construct(
        private GameRepositoryInterface $games,
        private GameFollowRepositoryInterface $follows,
        private Notifier $notifier,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(ApworldPromoted $promotion): void
    {
        $game = $this->games->findById($promotion->gameId);
        if (!$game instanceof Game) {
            return;
        }

        $followers = $this->follows->followerIds($game->getId());
        if ([] === $followers) {
            return;
        }

        $payload = [
            'gameId' => $game->getId(),
            'gameName' => $game->getName(),
            'gameSlug' => $game->getSlug(),
            'version' => $promotion->newVersion,
            'first' => null === $promotion->previousHash,
        ];
        foreach ($followers as $userId) {
            $this->notifier->notify($userId, self::NOTIFICATION_TYPE, $payload);
        }

        $this->logger->info('game_follow.followers_notified', ['gameId' => $game->getId(), 'count' => count($followers)]);
    }
}
