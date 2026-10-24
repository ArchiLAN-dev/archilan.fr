<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Community\Domain\Entity\Notification;
use App\GameSelection\Application\Handler\NotifyGameFollowersOfApworldPromotionHandler;
use App\GameSelection\Application\Message\ApworldPromoted;
use App\GameSelection\Application\Service\AdminGameLibrary;
use App\GameSelection\Domain\Entity\Game;
use App\GameSelection\Domain\Entity\GameFollow;
use App\Identity\Application\Command\DeleteAccount;
use App\Identity\Domain\Entity\User;

/**
 * Suivre un jeu pour être prévenu de ses nouveaux apworlds (story 38.22).
 */
final class GameFollowTest extends FunctionalTestCase
{
    public function testAMemberDoesNotFollowAGameAtFirst(): void
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $this->loginAs($this->member('alice@example.org'));

        $this->client->jsonRequest('GET', sprintf('/api/v1/games/%s/follow', $game->getId()));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['data' => ['following' => false]], $this->decodedJsonResponse());
    }

    public function testFollowingIsIdempotentAndRemembered(): void
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $alice = $this->member('alice@example.org');
        $this->loginAs($alice);

        $this->client->jsonRequest('PUT', sprintf('/api/v1/games/%s/follow', $game->getId()));
        self::assertResponseStatusCodeSame(200);
        $this->client->jsonRequest('PUT', sprintf('/api/v1/games/%s/follow', $game->getId()));
        self::assertResponseStatusCodeSame(200);
        self::assertSame(['data' => ['following' => true]], $this->decodedJsonResponse());

        self::assertCount(1, $this->entityManager->getRepository(GameFollow::class)->findBy(['userId' => $alice->getId()]));
    }

    public function testUnfollowingStopsTheFollow(): void
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $alice = $this->member('alice@example.org');
        $this->follow($alice, $game);
        $this->loginAs($alice);

        $this->client->jsonRequest('DELETE', sprintf('/api/v1/games/%s/follow', $game->getId()));

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['data' => ['following' => false]], $this->decodedJsonResponse());
        self::assertSame([], $this->entityManager->getRepository(GameFollow::class)->findBy(['userId' => $alice->getId()]));
    }

    public function testAnUnknownGameIsNotFound(): void
    {
        $this->loginAs($this->member('alice@example.org'));

        $this->client->jsonRequest('PUT', '/api/v1/games/no-such-game/follow');

        self::assertResponseStatusCodeSame(404);
    }

    public function testAVisitorCannotFollow(): void
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');

        $this->client->jsonRequest('PUT', sprintf('/api/v1/games/%s/follow', $game->getId()));

        self::assertResponseStatusCodeSame(401);
    }

    public function testANewApworldNotifiesOnlyTheFollowers(): void
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $alice = $this->member('alice@example.org');
        $bob = $this->member('bob@example.org');
        $this->follow($alice, $game);

        $this->handler()(new ApworldPromoted($game->getId(), 'old-hash', 'new-hash', null, 'v1.2.0'));

        $notices = $this->noticesOf($alice);
        self::assertCount(1, $notices);
        self::assertSame(
            ['gameId' => $game->getId(), 'gameName' => 'Hollow Knight', 'gameSlug' => 'hollow-knight', 'version' => 'v1.2.0', 'first' => false],
            $notices[0]->getPayload(),
        );
        self::assertSame([], $this->noticesOf($bob), 'who does not follow hears nothing');
    }

    public function testAFirstApworldSaysTheGameBecamePlayable(): void
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $alice = $this->member('alice@example.org');
        $this->follow($alice, $game);

        $this->handler()(new ApworldPromoted($game->getId(), null, 'first-hash', null));

        $notices = $this->noticesOf($alice);
        self::assertCount(1, $notices);
        $payload = $notices[0]->getPayload();
        self::assertTrue($payload['first'] ?? null);
        self::assertArrayHasKey('version', $payload);
        self::assertNull($payload['version']);
    }

    public function testAnErasedAccountFollowsNothingAnymore(): void
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $alice = $this->member('alice@example.org');
        $bob = $this->member('bob@example.org');
        $this->follow($alice, $game);
        $this->follow($bob, $game);

        $deleteAccount = self::getContainer()->get(DeleteAccount::class);
        self::assertInstanceOf(DeleteAccount::class, $deleteAccount);
        $deleteAccount->delete($alice);

        self::assertSame([], $this->entityManager->getRepository(GameFollow::class)->findBy(['userId' => $alice->getId()]));
        self::assertCount(1, $this->entityManager->getRepository(GameFollow::class)->findBy(['userId' => $bob->getId()]), 'the others keep theirs');
    }

    public function testARemovedGameTakesItsFollowsWithIt(): void
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $other = $this->createGame('Celeste', 'celeste');
        $alice = $this->member('alice@example.org');
        $this->follow($alice, $game);
        $this->follow($alice, $other);
        $gameId = $game->getId();

        $library = self::getContainer()->get(AdminGameLibrary::class);
        self::assertInstanceOf(AdminGameLibrary::class, $library);
        self::assertSame(['found' => true, 'errors' => []], $library->remove($gameId));

        self::assertSame([], $this->entityManager->getRepository(GameFollow::class)->findBy(['gameId' => $gameId]));
        self::assertCount(1, $this->entityManager->getRepository(GameFollow::class)->findBy(['userId' => $alice->getId()]), 'the other game stays followed');
    }

    public function testAMemberListsTheGamesTheyFollowByName(): void
    {
        $hollow = $this->createGame('Hollow Knight', 'hollow-knight');
        $celeste = $this->createGame('Celeste', 'celeste');
        $this->createGame('Tunic', 'tunic');
        $alice = $this->member('alice@example.org');
        $bob = $this->member('bob@example.org');
        $this->follow($alice, $hollow);
        $this->follow($alice, $celeste);
        $this->follow($bob, $hollow);
        $this->loginAs($alice);

        $this->client->jsonRequest('GET', '/api/v1/me/followed-games');

        self::assertResponseStatusCodeSame(200);
        self::assertSame(['data' => [
            ['gameId' => $celeste->getId(), 'gameName' => 'Celeste', 'gameSlug' => 'celeste'],
            ['gameId' => $hollow->getId(), 'gameName' => 'Hollow Knight', 'gameSlug' => 'hollow-knight'],
        ]], $this->decodedJsonResponse());
    }

    public function testAVisitorHasNoFollowedGames(): void
    {
        $this->client->jsonRequest('GET', '/api/v1/me/followed-games');

        self::assertResponseStatusCodeSame(401);
    }

    private function member(string $email): User
    {
        return $this->createUser($email, ['ROLE_USER'], ucfirst(strstr($email, '@', true) ?: 'Membre'));
    }

    private function follow(User $user, Game $game): void
    {
        $this->entityManager->persist(GameFollow::create($user->getId(), $game->getId(), new \DateTimeImmutable('2026-10-24T10:00:00+00:00')));
        $this->entityManager->flush();
    }

    private function handler(): NotifyGameFollowersOfApworldPromotionHandler
    {
        $handler = self::getContainer()->get(NotifyGameFollowersOfApworldPromotionHandler::class);
        self::assertInstanceOf(NotifyGameFollowersOfApworldPromotionHandler::class, $handler);

        return $handler;
    }

    /** @return list<Notification> */
    private function noticesOf(User $recipient): array
    {
        return $this->entityManager->getRepository(Notification::class)->findBy([
            'recipientId' => $recipient->getId(),
            'type' => NotifyGameFollowersOfApworldPromotionHandler::NOTIFICATION_TYPE,
        ]);
    }
}
