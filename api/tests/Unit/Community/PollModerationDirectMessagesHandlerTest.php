<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Exception\MemberDirectMessageException;
use App\Community\Application\Handler\PollModerationDirectMessagesHandler;
use App\Community\Application\Message\PollModerationDirectMessagesMessage;
use App\Community\Application\Message\PostModerationMessageToForumJob;
use App\Community\Application\Port\IncomingDirectMessage;
use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Domain\Entity\ModerationCase;
use App\Community\Domain\Entity\ModerationCaseMessage;
use App\Tests\Unit\CatalogSync\RecordingLogger;
use App\Tests\Unit\Payments\RecordingMessageBus;
use PHPUnit\Framework\TestCase;

/**
 * Story 39.4: every minute, what members answered the bot in private joins their open case and the staff
 * forum.
 */
final class PollModerationDirectMessagesHandlerTest extends TestCase
{
    private InMemoryModerationCaseRepository $cases;
    private InMemoryModerationCaseMessageRepository $messages;
    private RecordingMemberDirectMessages $dms;
    private RecordingMessageBus $bus;
    private RecordingLogger $logger;
    private ModerationCase $case;

    protected function setUp(): void
    {
        $this->cases = new InMemoryModerationCaseRepository();
        $this->messages = new InMemoryModerationCaseMessageRepository();
        $this->dms = new RecordingMemberDirectMessages();
        $this->bus = new RecordingMessageBus();
        $this->logger = new RecordingLogger();
        $this->case = $this->caseOf('user-1', 'dm-1', '1000');
    }

    public function testTheMembersAnswersJoinTheCaseAndGoToTheForum(): void
    {
        $this->dms->inbox['dm-1'] = [
            new IncomingDirectMessage('1001', 'bot-id', 'Réponse de la modération', '2026-09-27T12:00:00+00:00'),
            new IncomingDirectMessage('1002', 'discord-user-1', "OK pour le ban, mais j'aimerais être remboursé", '2026-09-27T12:01:00+00:00'),
        ];

        $this->poll();

        self::assertCount(1, $this->messages->messages, "the bot's own messages are not answers");
        $answer = $this->messages->messages[0];
        self::assertSame($this->case->getId(), $answer->getCaseId());
        self::assertSame('user-1', $answer->getAuthorUserId());
        self::assertSame(ModerationCaseMessage::SOURCE_DISCORD_DM, $answer->getSource());
        self::assertSame('1002', $answer->getDiscordMessageId());
        self::assertEquals(new \DateTimeImmutable('2026-09-27T12:01:00+00:00'), $answer->getCreatedAt());
        self::assertSame('1002', $this->case->getDirectMessageCursor());
        self::assertEquals([new PostModerationMessageToForumJob($answer->getId())], $this->bus->messages);
    }

    public function testNothingIsImportedTwice(): void
    {
        $this->dms->inbox['dm-1'] = [new IncomingDirectMessage('1002', 'discord-user-1', 'Bonjour', '2026-09-27T12:01:00+00:00')];

        $this->poll();
        $this->poll();

        self::assertCount(1, $this->messages->messages);
    }

    public function testAnAttachmentAloneIsKeptAsItsLink(): void
    {
        $this->dms->inbox['dm-1'] = [
            new IncomingDirectMessage('1002', 'discord-user-1', 'https://cdn.discordapp.com/attachments/1/2/preuve.png', '2026-09-27T12:01:00+00:00'),
            new IncomingDirectMessage('1003', 'discord-user-1', '   ', '2026-09-27T12:02:00+00:00'),
        ];

        $this->poll();

        self::assertCount(1, $this->messages->messages, 'an empty message says nothing');
        self::assertSame('https://cdn.discordapp.com/attachments/1/2/preuve.png', $this->messages->messages[0]->getBody());
        self::assertSame('1003', $this->case->getDirectMessageCursor(), 'the cursor moves past it anyway');
    }

    public function testOnlyOpenCasesAreRead(): void
    {
        $this->case->close(new \DateTimeImmutable());
        $this->dms->inbox['dm-1'] = [new IncomingDirectMessage('1002', 'discord-user-1', 'Bonjour', '2026-09-27T12:01:00+00:00')];

        $this->poll();

        self::assertSame([], $this->messages->messages);
    }

    public function testACaseInErrorDoesNotStopTheOthers(): void
    {
        $other = $this->caseOf('user-2', 'dm-2', '2000');
        $this->dms->inbox['dm-2'] = [new IncomingDirectMessage('2001', 'discord-user-2', 'Bonjour', '2026-09-27T12:01:00+00:00')];
        $this->dms->failingChannels = ['dm-1'];

        $this->poll();

        self::assertCount(1, $this->messages->messages);
        self::assertSame($other->getId(), $this->messages->messages[0]->getCaseId());
        self::assertContains(['level' => 'warning', 'message' => 'moderation_dm.poll_failed'], $this->logger->logs);
    }

    public function testNoBotNoPolling(): void
    {
        $this->dms = new RecordingMemberDirectMessages(configured: false);
        $this->dms->failReadingWith = new MemberDirectMessageException('must not be read');

        $this->poll();

        self::assertSame([], $this->logger->logs);
    }

    private function caseOf(string $userId, string $channelId, string $cursor): ModerationCase
    {
        $case = ModerationCase::open($userId, new \DateTimeImmutable('2026-09-20'));
        $case->attachDirectMessageChannel($channelId, $cursor);
        $this->cases->save($case);

        return $case;
    }

    private function poll(): void
    {
        $gateway = self::createStub(MemberModerationGatewayInterface::class);
        $gateway->method('discordIdOf')->willReturnCallback(static fn (string $userId): string => 'discord-'.$userId);

        new PollModerationDirectMessagesHandler($this->cases, $this->messages, $gateway, $this->dms, $this->bus, $this->logger)(new PollModerationDirectMessagesMessage());
    }
}
