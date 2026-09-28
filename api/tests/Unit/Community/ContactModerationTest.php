<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Command\ContactModeration;
use App\Community\Application\Command\ContactModerationOutcome;
use App\Community\Application\Message\PostModerationMessageToForumJob;
use App\Community\Domain\Entity\ModerationAction;
use App\Community\Domain\Entity\ModerationCase;
use App\Community\Domain\Entity\ModerationCaseMessage;
use App\Community\Domain\Repository\ModerationActionRepositoryInterface;
use App\Tests\Unit\Payments\RecordingMessageBus;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Story 39.2: a sanctioned member writes to the moderation; the message joins their case and goes to the
 * staff forum after the commit.
 */
final class ContactModerationTest extends TestCase
{
    private InMemoryModerationCaseRepository $cases;
    private InMemoryModerationCaseMessageRepository $messages;
    private RecordingMessageBus $bus;
    private MockClock $clock;
    /** @var list<ModerationAction> */
    private array $history = [];

    protected function setUp(): void
    {
        $this->cases = new InMemoryModerationCaseRepository();
        $this->messages = new InMemoryModerationCaseMessageRepository();
        $this->bus = new RecordingMessageBus();
        $this->clock = new MockClock('2026-09-27 10:00:00');
    }

    public function testASanctionedMemberWithoutACaseYetGetsOne(): void
    {
        $this->sanctioned();

        self::assertSame(ContactModerationOutcome::Sent, $this->command()->write('user-1', "J'aimerais être remboursé"));

        $case = $this->cases->findByTargetUserId('user-1');
        self::assertNotNull($case);
        self::assertCount(1, $this->messages->messages);
        self::assertSame($case->getId(), $this->messages->messages[0]->getCaseId());
        self::assertSame(1, $this->messages->flushes);
        self::assertEquals([new PostModerationMessageToForumJob($this->messages->messages[0]->getId())], $this->bus->messages);
    }

    public function testTheMessageJoinsTheExistingCase(): void
    {
        $this->sanctioned();
        $case = ModerationCase::open('user-1', $this->clock->now());
        $this->cases->save($case);

        $this->command()->write('user-1', 'Bonjour');

        self::assertSame($case->getId(), $this->messages->messages[0]->getCaseId());
    }

    public function testAMemberNeverSanctionedIsRefused(): void
    {
        self::assertSame(ContactModerationOutcome::NotSanctioned, $this->command()->write('user-1', 'Bonjour'));

        self::assertNull($this->cases->findByTargetUserId('user-1'));
        self::assertSame([], $this->messages->messages);
        self::assertSame([], $this->bus->messages);
    }

    public function testANoteAloneGivesNothingToContest(): void
    {
        // Story 39.10: the member does not even know about it.
        $this->history[] = new ModerationAction('a-0', 'admin-1', 'user-1', ModerationAction::ACTION_NOTE, 'À surveiller', new \DateTimeImmutable('2026-09-20'));

        self::assertSame(ContactModerationOutcome::NotSanctioned, $this->command()->write('user-1', 'Bonjour'));
    }

    public function testAnEmptyOrOversizedMessageIsInvalid(): void
    {
        $this->sanctioned();

        self::assertSame(ContactModerationOutcome::Invalid, $this->command()->write('user-1', '   '));
        self::assertSame(ContactModerationOutcome::Invalid, $this->command()->write('user-1', str_repeat('a', 2001)));
        self::assertSame([], $this->messages->messages);
    }

    public function testFiveMessagesAnHourAtMost(): void
    {
        $this->sanctioned();
        for ($i = 0; $i < 5; ++$i) {
            self::assertSame(ContactModerationOutcome::Sent, $this->command()->write('user-1', 'Message '.$i));
        }

        self::assertSame(ContactModerationOutcome::TooMany, $this->command()->write('user-1', 'Un de trop'));
        self::assertCount(5, $this->messages->messages);

        $this->clock->modify('+61 minutes');
        self::assertSame(ContactModerationOutcome::Sent, $this->command()->write('user-1', 'Une heure plus tard'));
    }

    public function testAnswersToTheBotDoNotCountAgainstTheSite(): void
    {
        // Story 39.9: the site's limit is the site's; what the member told the bot is capped apart.
        $this->sanctioned();
        $case = ModerationCase::open('user-1', $this->clock->now());
        $this->cases->save($case);
        for ($i = 0; $i < 5; ++$i) {
            $this->messages->save(ModerationCaseMessage::fromMemberDirectMessage($case->getId(), 'user-1', 'MP '.$i, $this->clock->now(), (string) (2000 + $i)));
        }

        self::assertSame(ContactModerationOutcome::Sent, $this->command()->write('user-1', 'Depuis le site'));
    }

    public function testABusThatCannotTakeTheJobKeepsTheMessage(): void
    {
        $this->sanctioned();
        $down = self::createStub(MessageBusInterface::class);
        $down->method('dispatch')->willThrowException(new \RuntimeException('broker down'));

        self::assertSame(ContactModerationOutcome::Sent, $this->command($down)->write('user-1', 'Bonjour'));
        self::assertCount(1, $this->messages->messages, 'the staff still reads it on the site');
    }

    private function sanctioned(): void
    {
        $this->history[] = new ModerationAction('a-1', 'admin-1', 'user-1', ModerationAction::ACTION_WARN, 'Spam', new \DateTimeImmutable('2026-09-20'));
    }

    private function command(?MessageBusInterface $bus = null): ContactModeration
    {
        $actions = self::createStub(ModerationActionRepositoryInterface::class);
        $actions->method('forTarget')->willReturnCallback(fn (string $id, int $limit): array => array_slice(array_values(array_filter(
            $this->history,
            static fn (ModerationAction $a): bool => $a->getTargetUserId() === $id,
        )), 0, $limit));

        return new ContactModeration($this->cases, $this->messages, $actions, $bus ?? $this->bus, $this->clock, new NullLogger());
    }
}
