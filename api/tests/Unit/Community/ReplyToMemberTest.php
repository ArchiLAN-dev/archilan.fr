<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Command\ReplyToMember;
use App\Community\Application\Command\ReplyToMemberOutcome;
use App\Community\Application\Message\DeliverStaffReplyJob;
use App\Community\Domain\Entity\ModerationAction;
use App\Community\Domain\Entity\ModerationCase;
use App\Community\Domain\Entity\ModerationCaseMessage;
use App\Community\Domain\Entity\Notification;
use App\Community\Domain\Repository\ModerationActionRepositoryInterface;
use App\Tests\Unit\GameSelection\SpyNotifier;
use App\Tests\Unit\Payments\RecordingMessageBus;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;

/**
 * Story 39.3: the staff answers a member from the site; the answer joins the case, notifies the member and
 * goes out to Discord after the commit.
 */
final class ReplyToMemberTest extends TestCase
{
    private InMemoryModerationCaseRepository $cases;
    private InMemoryModerationCaseMessageRepository $messages;
    private RecordingMessageBus $bus;
    private SpyNotifier $notifier;
    /** @var list<ModerationAction> */
    private array $history = [];

    protected function setUp(): void
    {
        $this->cases = new InMemoryModerationCaseRepository();
        $this->messages = new InMemoryModerationCaseMessageRepository();
        $this->bus = new RecordingMessageBus();
        $this->notifier = new SpyNotifier();
    }

    public function testTheReplyJoinsTheCaseNotifiesTheMemberAndIsDelivered(): void
    {
        $case = ModerationCase::open('user-1', new \DateTimeImmutable('2026-09-20'));
        $this->cases->save($case);

        self::assertSame(ReplyToMemberOutcome::Sent, $this->command()->reply('admin-1', 'user-1', 'Le remboursement est en cours.'));

        self::assertCount(1, $this->messages->messages);
        $reply = $this->messages->messages[0];
        self::assertSame($case->getId(), $reply->getCaseId());
        self::assertSame(ModerationCaseMessage::AUTHOR_STAFF, $reply->getAuthorRole());
        self::assertSame('admin-1', $reply->getAuthorUserId());
        self::assertSame(1, $this->messages->flushes);
        self::assertSame([['recipientId' => 'user-1', 'type' => Notification::TYPE_MODERATION_REPLY, 'payload' => []]], $this->notifier->sent);
        self::assertEquals([new DeliverStaffReplyJob($reply->getId())], $this->bus->messages);
    }

    public function testASanctionOlderThanTheCasesOpensOne(): void
    {
        $this->history[] = new ModerationAction('a-1', 'admin-1', 'user-1', ModerationAction::ACTION_BAN, 'Triche', new \DateTimeImmutable('2026-09-01'));

        self::assertSame(ReplyToMemberOutcome::Sent, $this->command()->reply('admin-1', 'user-1', 'Bonjour'));

        self::assertNotNull($this->cases->findByTargetUserId('user-1'));
    }

    public function testNoSanctionNoReply(): void
    {
        self::assertSame(ReplyToMemberOutcome::NotSanctioned, $this->command()->reply('admin-1', 'user-1', 'Bonjour'));

        self::assertSame([], $this->messages->messages);
        self::assertSame([], $this->notifier->sent);
    }

    public function testAnEmptyReplyIsInvalid(): void
    {
        $this->cases->save(ModerationCase::open('user-1', new \DateTimeImmutable('2026-09-20')));

        self::assertSame(ReplyToMemberOutcome::Invalid, $this->command()->reply('admin-1', 'user-1', '  '));
        self::assertSame([], $this->messages->messages);
        self::assertSame([], $this->bus->messages);
    }

    private function command(): ReplyToMember
    {
        $actions = self::createStub(ModerationActionRepositoryInterface::class);
        $actions->method('forTarget')->willReturnCallback(fn (string $id, int $limit): array => array_slice(array_values(array_filter(
            $this->history,
            static fn (ModerationAction $a): bool => $a->getTargetUserId() === $id,
        )), 0, $limit));

        return new ReplyToMember($this->cases, $this->messages, $actions, $this->notifier, $this->bus, new MockClock('2026-09-27 11:00:00'), new NullLogger());
    }
}
