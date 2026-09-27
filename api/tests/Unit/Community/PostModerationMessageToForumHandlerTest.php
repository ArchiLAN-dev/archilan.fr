<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Exception\ModerationForumDeliveryException;
use App\Community\Application\Exception\ModerationForumTemporarilyUnavailableException;
use App\Community\Application\Handler\PostModerationMessageToForumHandler;
use App\Community\Application\Message\PostModerationMessageToForumJob;
use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Support\ModerationForumDelivery;
use App\Community\Application\Support\ModerationForumMessageFactory;
use App\Community\Domain\Entity\ModerationCase;
use App\Community\Domain\Entity\ModerationCaseMessage;
use App\Tests\Unit\CatalogSync\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Story 39.2: a member's message reaches their case's post in the staff forum.
 */
final class PostModerationMessageToForumHandlerTest extends TestCase
{
    private InMemoryModerationCaseRepository $cases;
    private InMemoryModerationCaseMessageRepository $messages;
    private RecordingModerationForum $forum;
    private RecordingLogger $logger;
    private ModerationCase $case;

    protected function setUp(): void
    {
        $this->cases = new InMemoryModerationCaseRepository();
        $this->messages = new InMemoryModerationCaseMessageRepository();
        $this->forum = new RecordingModerationForum();
        $this->logger = new RecordingLogger();
        $this->case = ModerationCase::open('user-1', new \DateTimeImmutable('2026-09-20'));
        $this->cases->save($this->case);
    }

    public function testTheMessageIsPostedInTheCasePostWithoutRetaggingIt(): void
    {
        $this->case->attachForumThread('thread-7');

        $this->handle("OK pour le ban, mais j'aimerais être remboursé <@&42> @everyone");

        self::assertCount(1, $this->forum->posts);
        self::assertSame('thread-7', $this->forum->posts[0]['threadId']);
        $message = $this->forum->posts[0]['message'];
        self::assertSame('Message du membre', $message->title);
        self::assertSame("OK pour le ban, mais j'aimerais être remboursé <@&42> @everyone", $message->description, 'posted as written; the forum never lets it ping');
        self::assertNull($message->tag, 'the tag follows the sanctions, not the messages');
        self::assertContains(['name' => 'Membre', 'value' => 'Lone (<@123456789>)'], $message->fields);
        self::assertContains(['name' => 'Écrit depuis', 'value' => 'le site'], $message->fields);
    }

    public function testACaseWithoutAPostYetGetsOne(): void
    {
        $this->handle('Bonjour');

        self::assertCount(1, $this->forum->openedThreads);
        self::assertSame('Lone', $this->forum->openedThreads[0]['title']);
        self::assertSame('thread-1', $this->case->getForumThreadId());
        self::assertSame(1, $this->cases->flushes);
    }

    public function testAnUnconfiguredForumDoesNothing(): void
    {
        $this->forum = new RecordingModerationForum(configured: false);

        $this->handle('Bonjour');

        self::assertSame([], $this->forum->openedThreads);
        self::assertSame([], $this->forum->posts);
    }

    public function testAPassingFailureIsHandedBackForARetry(): void
    {
        $this->forum->failWith = new ModerationForumDeliveryException('Discord 429', transient: true);

        $this->expectException(ModerationForumTemporarilyUnavailableException::class);
        $this->handle('Bonjour');
    }

    public function testADefinitiveRefusalIsLoggedAndDropped(): void
    {
        $this->forum->failWith = new ModerationForumDeliveryException('Discord 403: Missing Permissions', transient: false);

        $this->handle('Bonjour');

        self::assertContains(['level' => 'warning', 'message' => 'moderation_forum.not_posted'], $this->logger->logs);
    }

    public function testAnUnknownMessageIsIgnored(): void
    {
        $this->handler()(new PostModerationMessageToForumJob('missing'));

        self::assertSame([], $this->forum->openedThreads);
    }

    private function handle(string $body): void
    {
        $message = ModerationCaseMessage::fromMember($this->case->getId(), 'user-1', $body, new \DateTimeImmutable('2026-09-27 10:00:00'));
        $this->messages->save($message);

        $this->handler()(new PostModerationMessageToForumJob($message->getId()));
    }

    private function handler(): PostModerationMessageToForumHandler
    {
        $gateway = self::createStub(MemberModerationGatewayInterface::class);
        $gateway->method('discordIdOf')->willReturn('123456789');

        $directory = self::createStub(CommunityUserDirectoryQueryInterface::class);
        $directory->method('namesFor')->willReturn(['user-1' => 'Lone']);

        return new PostModerationMessageToForumHandler(
            $this->messages,
            $this->cases,
            $gateway,
            $directory,
            new ModerationForumMessageFactory('https://archilan.fr'),
            new ModerationForumDelivery($this->forum, $this->cases, $this->logger),
        );
    }
}
