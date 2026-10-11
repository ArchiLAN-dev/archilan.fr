<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Application\Command\SyncRunListingsToDiscord;
use App\PersonalRuns\Application\Port\RunListingChannelInterface;
use App\PersonalRuns\Domain\Entity\Run;
use App\PersonalRuns\Domain\Entity\RunParticipant;
use App\Tests\Fixtures\PersonalRuns\SpyRunListingChannel;

/**
 * Story 43.27: the Discord channel says what the listings page says - one message per listing, the role mentioned
 * once per run.
 */
final class RunListingsDiscordTest extends FunctionalTestCase
{
    private User $owner;

    private Run $run;

    private SpyRunListingChannel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->createUser('owner@example.org', ['ROLE_USER'], 'Owner', 'owner');
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        $this->run = Run::create($this->owner->getId(), 'Async du dimanche', new \DateTimeImmutable());
        $this->run->configureGames([['gameId' => $game->getId()]], new \DateTimeImmutable());
        $this->run->listForMembers('On cherche deux joueurs.', 3, new \DateTimeImmutable('2026-11-02T20:00:00+01:00'), new \DateTimeImmutable());
        $this->entityManager->persist($this->run);
        $this->entityManager->flush();

        $this->channel = new SpyRunListingChannel();
        self::getContainer()->set(RunListingChannelInterface::class, $this->channel);
    }

    public function testANewListingIsPostedWithTheRoleMentioned(): void
    {
        $result = $this->sync()->sync();

        self::assertSame(1, $result->posted);
        self::assertSame([['call' => 'post', 'messageId' => 'm1', 'mention' => true]], $this->channel->calls);
        $announcement = $this->channel->messages['m1'];
        self::assertSame('Async du dimanche', $announcement->title);
        self::assertSame('Owner', $announcement->hostName);
        self::assertSame('On cherche deux joueurs.', $announcement->pitch);
        self::assertSame(['Hollow Knight'], $announcement->games);
        self::assertSame('3 places libres sur 3', $announcement->seatsLabel());
        self::assertStringEndsWith('/communaute/parties', $announcement->url);
    }

    public function testAnUnchangedListingIsLeftAloneAndAChangedOneIsEditedWithoutMention(): void
    {
        $this->sync()->sync();
        $this->sync()->sync();
        self::assertSame(['post'], $this->channel->callNames(), 'nothing changed, nothing sent');

        $this->join('joiner');
        $result = $this->sync()->sync();

        self::assertSame(1, $result->edited);
        self::assertSame(['post', 'edit'], $this->channel->callNames());
        self::assertSame('2 places libres sur 3', $this->channel->messages['m1']->seatsLabel());
    }

    public function testAListingNoLongerOnlineHasItsMessageDeleted(): void
    {
        $this->sync()->sync();

        $this->reloadRun()->openTo(Run::OPEN_FRIENDS, null, new \DateTimeImmutable());
        $this->entityManager->flush();
        $result = $this->sync()->sync();

        self::assertSame(1, $result->deleted);
        self::assertSame(['post', 'delete'], $this->channel->callNames());
        self::assertSame([], $this->channel->messages);
    }

    public function testAFullListingHasItsMessageDeleted(): void
    {
        $this->sync()->sync();
        $this->join('a');
        $this->join('b');
        $this->join('c');

        $this->sync()->sync();

        self::assertSame(['post', 'delete'], $this->channel->callNames());
    }

    public function testTheRoleIsMentionedOnlyOncePerRun(): void
    {
        $this->sync()->sync();
        $this->reloadRun()->openTo(Run::OPEN_FRIENDS, null, new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->sync()->sync();

        $this->reloadRun()->listForMembers('On cherche toujours.', 3, null, new \DateTimeImmutable());
        $this->entityManager->flush();
        $this->sync()->sync();

        self::assertSame(['post', 'delete', 'post'], $this->channel->callNames());
        self::assertTrue($this->channel->calls[0]['mention']);
        self::assertFalse($this->channel->calls[2]['mention'], 'back online, posted again without a ping');
    }

    public function testAMessageDeletedOnDiscordIsPostedAgainWithoutMention(): void
    {
        $this->sync()->sync();
        unset($this->channel->messages['m1']);

        $this->join('joiner');
        $this->sync()->sync();

        self::assertSame(['post', 'edit', 'post'], $this->channel->callNames());
        self::assertFalse($this->channel->calls[2]['mention']);
        self::assertArrayHasKey('m2', $this->channel->messages);
    }

    public function testDiscordDownIsCaughtUpOnTheNextPass(): void
    {
        $this->channel->failing = true;
        $result = $this->sync()->sync();
        self::assertSame(1, $result->failed);

        $this->channel->failing = false;
        $result = $this->sync()->sync();

        self::assertSame(1, $result->posted);
        self::assertTrue($this->channel->calls[0]['mention'], 'the failed post never counted as the mention');
    }

    public function testNothingIsSentWithoutAChannel(): void
    {
        $this->channel->enabled = false;

        $result = $this->sync()->sync();

        self::assertSame(0, $result->posted + $result->failed);
        self::assertSame([], $this->channel->calls);
    }

    public function testPublishingAListingTriggersASyncOffTheRequest(): void
    {
        $this->client->disableReboot();
        $this->loginAs($this->owner);

        $this->client->jsonRequest('PUT', '/api/v1/runs/'.$this->run->getId().'/openness', [
            'openness' => Run::OPEN_MEMBERS,
            'seatsWanted' => 2,
            'pitch' => 'Nouveau message.',
        ]);
        self::assertResponseStatusCodeSame(200);

        self::assertSame([], $this->channel->calls, 'Discord is not called during the request');
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(\Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport::class, $transport);
        self::assertCount(1, $transport->getSent());
    }

    private function sync(): SyncRunListingsToDiscord
    {
        $sync = self::getContainer()->get(SyncRunListingsToDiscord::class);
        self::assertInstanceOf(SyncRunListingsToDiscord::class, $sync);

        return $sync;
    }

    private function join(string $slug): void
    {
        $member = $this->createUser($slug.'@example.org', ['ROLE_USER'], ucfirst($slug), $slug);
        $this->entityManager->persist(RunParticipant::create($this->run->getId(), $member->getId(), new \DateTimeImmutable()));
        $this->entityManager->flush();
    }

    private function reloadRun(): Run
    {
        $run = $this->entityManager->find(Run::class, $this->run->getId());
        self::assertInstanceOf(Run::class, $run);

        return $run;
    }
}
