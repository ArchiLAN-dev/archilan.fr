<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Exception\MemberDirectMessageException;
use App\Community\Application\Exception\MemberDirectMessageTemporarilyUnavailableException;
use App\Community\Application\Exception\ModerationForumDeliveryException;
use App\Community\Application\Exception\ModerationForumTemporarilyUnavailableException;
use App\Community\Application\Handler\DeliverStaffReplyHandler;
use App\Community\Application\Message\DeliverStaffReplyJob;
use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Support\ModerationForumDelivery;
use App\Community\Application\Support\ModerationForumMessageFactory;
use App\Community\Domain\Entity\ModerationCase;
use App\Community\Domain\Entity\ModerationCaseMessage;
use App\Tests\Unit\CatalogSync\RecordingLogger;
use PHPUnit\Framework\TestCase;

/**
 * Story 39.3: a staff reply goes to the member by the bot's direct message, then to the staff forum with the
 * outcome of that message.
 */
final class DeliverStaffReplyHandlerTest extends TestCase
{
    private InMemoryModerationCaseRepository $cases;
    private InMemoryModerationCaseMessageRepository $messages;
    private RecordingModerationForum $forum;
    private RecordingMemberDirectMessages $dms;
    private RecordingLogger $logger;
    private ModerationCase $case;
    private ?string $discordId = '123456789';

    protected function setUp(): void
    {
        $this->cases = new InMemoryModerationCaseRepository();
        $this->messages = new InMemoryModerationCaseMessageRepository();
        $this->forum = new RecordingModerationForum();
        $this->dms = new RecordingMemberDirectMessages();
        $this->logger = new RecordingLogger();
        $this->case = ModerationCase::open('user-1', new \DateTimeImmutable('2026-09-20'));
        $this->case->attachForumThread('thread-7');
        $this->cases->save($this->case);
    }

    public function testTheMemberGetsTheReplyInPrivateAndTheStaffSeesItInTheForum(): void
    {
        $reply = $this->handle('Le remboursement est en cours.');

        self::assertCount(1, $this->dms->sent);
        self::assertSame('123456789', $this->dms->sent[0]['discordUserId']);
        self::assertSame('Le remboursement est en cours.', $this->dms->sent[0]['message']->description);
        self::assertSame(ModerationCaseMessage::DM_SENT, $reply->getDiscordDmStatus());

        self::assertCount(1, $this->forum->posts);
        $post = $this->forum->posts[0]['message'];
        self::assertSame('thread-7', $this->forum->posts[0]['threadId']);
        self::assertSame('Réponse envoyée au membre', $post->title);
        self::assertNull($post->tag);
        self::assertContains(['name' => 'Modérateur', 'value' => 'Jean'], $post->fields);
        self::assertContains(['name' => 'Message privé Discord', 'value' => 'envoyé'], $post->fields);
    }

    public function testAnUnlinkedAccountGetsNoPrivateMessage(): void
    {
        $this->discordId = null;

        $reply = $this->handle('Bonjour');

        self::assertSame([], $this->dms->sent);
        self::assertSame(ModerationCaseMessage::DM_NOT_LINKED, $reply->getDiscordDmStatus());
        self::assertContains(['name' => 'Message privé Discord', 'value' => 'compte Discord non lié'], $this->forum->posts[0]['message']->fields);
    }

    public function testClosedPrivateMessagesAreReportedNotRetried(): void
    {
        $this->dms->failWith = new MemberDirectMessageException('Discord 403: Cannot send messages to this user');

        $reply = $this->handle('Bonjour');

        self::assertSame(ModerationCaseMessage::DM_FAILED, $reply->getDiscordDmStatus());
        self::assertContains(['name' => 'Message privé Discord', 'value' => 'impossible (MP fermés ou serveur quitté)'], $this->forum->posts[0]['message']->fields);
        self::assertContains(['level' => 'warning', 'message' => 'moderation_reply.dm_not_sent'], $this->logger->logs);
    }

    public function testABotWithoutTokenIsReported(): void
    {
        $this->dms = new RecordingMemberDirectMessages(configured: false);

        self::assertSame(ModerationCaseMessage::DM_UNAVAILABLE, $this->handle('Bonjour')->getDiscordDmStatus());
    }

    public function testAPassingDirectMessageFailureIsRetriedWithoutAForumPostYet(): void
    {
        $this->dms->failWith = new MemberDirectMessageException('Discord 429', transient: true);
        $reply = $this->reply('Bonjour');

        try {
            $this->handler()(new DeliverStaffReplyJob($reply->getId()));
            self::fail('a passing failure must reach Messenger to be retried');
        } catch (MemberDirectMessageTemporarilyUnavailableException) {
        }

        self::assertNull($reply->getDiscordDmStatus());
        self::assertSame([], $this->forum->posts);
    }

    public function testARetryAfterAForumFailureNeverSendsThePrivateMessageTwice(): void
    {
        $this->forum->failWith = new ModerationForumDeliveryException('Discord 503', transient: true);
        $reply = $this->reply('Bonjour');

        try {
            $this->handler()(new DeliverStaffReplyJob($reply->getId()));
            self::fail('the forum failure must be retried');
        } catch (ModerationForumTemporarilyUnavailableException) {
        }
        self::assertSame(ModerationCaseMessage::DM_SENT, $reply->getDiscordDmStatus(), 'kept before the forum is tried');

        $this->forum->failWith = null;
        $this->handler()(new DeliverStaffReplyJob($reply->getId()));

        self::assertCount(1, $this->dms->sent);
        self::assertCount(1, $this->forum->posts);
    }

    public function testAMemberMessageIsNotAReply(): void
    {
        $message = ModerationCaseMessage::fromMember($this->case->getId(), 'user-1', 'Bonjour', new \DateTimeImmutable());
        $this->messages->save($message);

        $this->handler()(new DeliverStaffReplyJob($message->getId()));

        self::assertSame([], $this->dms->sent);
        self::assertSame([], $this->forum->posts);
    }

    private function reply(string $body): ModerationCaseMessage
    {
        $reply = ModerationCaseMessage::fromStaff($this->case->getId(), 'admin-1', $body, new \DateTimeImmutable('2026-09-27 11:00:00'));
        $this->messages->save($reply);

        return $reply;
    }

    private function handle(string $body): ModerationCaseMessage
    {
        $reply = $this->reply($body);
        $this->handler()(new DeliverStaffReplyJob($reply->getId()));

        return $reply;
    }

    private function handler(): DeliverStaffReplyHandler
    {
        $gateway = self::createStub(MemberModerationGatewayInterface::class);
        $gateway->method('discordIdOf')->willReturn($this->discordId);

        $directory = self::createStub(CommunityUserDirectoryQueryInterface::class);
        $directory->method('namesFor')->willReturn(['user-1' => 'Lone', 'admin-1' => 'Jean']);

        return new DeliverStaffReplyHandler(
            $this->messages,
            $this->cases,
            $gateway,
            $directory,
            new ModerationForumMessageFactory('https://archilan.fr'),
            $this->dms,
            new ModerationForumDelivery($this->forum, $this->cases, $this->logger),
            $this->logger,
        );
    }
}
