<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Exception\ModerationForumDeliveryException;
use App\Community\Application\Exception\ModerationForumTemporarilyUnavailableException;
use App\Community\Application\Handler\PostModerationActionToForumHandler;
use App\Community\Application\Message\PostModerationActionToForumJob;
use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Application\Port\MemberModerationState;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Support\ModerationForumDelivery;
use App\Community\Application\Support\ModerationForumMessageFactory;
use App\Community\Domain\Entity\ModerationAction;
use App\Community\Domain\Repository\ModerationActionRepositoryInterface;
use App\Tests\Unit\CatalogSync\RecordingLogger;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Story 39.1: every sanction of the site opens or feeds the member's case, mirrored by one post in the
 * staff forum on Discord.
 */
final class PostModerationActionToForumHandlerTest extends TestCase
{
    /** @var array<string, ModerationAction> */
    private array $actions = [];
    private InMemoryModerationCaseRepository $cases;
    private RecordingModerationForum $forum;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->cases = new InMemoryModerationCaseRepository();
        $this->forum = new RecordingModerationForum();
        $this->logger = new RecordingLogger();
    }

    public function testAFirstSanctionOpensTheCaseAndItsForumPost(): void
    {
        $this->handle($this->action('a-1', ModerationAction::ACTION_WARN, 'Spam'));

        self::assertCount(1, $this->forum->openedThreads);
        self::assertSame('Lone', $this->forum->openedThreads[0]['title'], 'the post is named after the member');
        self::assertSame('Avertissement', $this->forum->openedThreads[0]['message']->tag);
        $case = $this->cases->findByTargetUserId('user-1');
        self::assertNotNull($case);
        self::assertSame('thread-1', $case->getForumThreadId());
        self::assertTrue($case->isOpen());
    }

    public function testTheNextSanctionsGoToTheSamePost(): void
    {
        $this->handle($this->action('a-1', ModerationAction::ACTION_WARN, 'Spam'));
        $this->handle($this->action('a-2', ModerationAction::ACTION_BAN, 'Récidive'));

        self::assertCount(1, $this->forum->openedThreads, 'one post per member');
        self::assertCount(1, $this->forum->posts);
        self::assertSame('thread-1', $this->forum->posts[0]['threadId']);
        self::assertSame('Ban', $this->forum->posts[0]['message']->tag);
    }

    public function testALiftClosesTheCaseAndANewSanctionReopensIt(): void
    {
        $this->handle($this->action('a-1', ModerationAction::ACTION_BAN, 'Triche'));
        $this->handle($this->action('a-2', ModerationAction::ACTION_LIFT, 'Levée de la sanction'));
        self::assertFalse($this->cases->findByTargetUserId('user-1')?->isOpen());

        $this->handle($this->action('a-3', ModerationAction::ACTION_WARN, 'Encore'));
        self::assertTrue($this->cases->findByTargetUserId('user-1')?->isOpen());
    }

    public function testAnUnconfiguredForumDoesNothing(): void
    {
        $this->forum = new RecordingModerationForum(configured: false);

        $this->handle($this->action('a-1', ModerationAction::ACTION_BAN, 'Triche'));

        self::assertSame([], $this->forum->openedThreads);
        self::assertNull($this->cases->findByTargetUserId('user-1'), 'no case without a forum to mirror it yet');
    }

    public function testAPassingFailureIsHandedBackForARetry(): void
    {
        $this->forum->failWith = new ModerationForumDeliveryException('Discord 503', transient: true);

        try {
            $this->handle($this->action('a-1', ModerationAction::ACTION_BAN, 'Triche'));
            self::fail('a passing failure must reach Messenger to be retried');
        } catch (ModerationForumTemporarilyUnavailableException) {
        }

        self::assertNull($this->cases->findByTargetUserId('user-1')?->getForumThreadId(), 'no post recorded that does not exist');

        // The retry creates it.
        $this->forum->failWith = null;
        $this->handle($this->action('a-1', ModerationAction::ACTION_BAN, 'Triche'));
        self::assertSame('thread-1', $this->cases->findByTargetUserId('user-1')?->getForumThreadId());
    }

    public function testADefinitiveRefusalIsLoggedAndDropped(): void
    {
        $this->forum->failWith = new ModerationForumDeliveryException('Discord 403: Missing Permissions', transient: false);

        $this->handle($this->action('a-1', ModerationAction::ACTION_BAN, 'Triche'));

        self::assertContains(['level' => 'warning', 'message' => 'moderation_forum.not_posted'], $this->logger->logs);
    }

    public function testAnUnknownActionIsIgnored(): void
    {
        $this->handler()(new PostModerationActionToForumJob('missing'));

        self::assertSame([], $this->forum->openedThreads);
    }

    private function action(string $id, string $type, string $reason): ModerationAction
    {
        $action = new ModerationAction($id, 'admin-1', 'user-1', $type, $reason, new \DateTimeImmutable('2026-09-27 10:00:00'));
        $this->actions[$id] = $action;

        return $action;
    }

    private function handle(ModerationAction $action): void
    {
        $this->handler()(new PostModerationActionToForumJob($action->getId()));
    }

    private function handler(): PostModerationActionToForumHandler
    {
        $actions = self::createStub(ModerationActionRepositoryInterface::class);
        $actions->method('findById')->willReturnCallback(fn (string $id): ?ModerationAction => $this->actions[$id] ?? null);

        $gateway = self::createStub(MemberModerationGatewayInterface::class);
        $gateway->method('discordIdOf')->willReturn('123456789');
        $gateway->method('currentState')->willReturn(new MemberModerationState(null, null, null));

        $directory = self::createStub(CommunityUserDirectoryQueryInterface::class);
        $directory->method('namesFor')->willReturn(['user-1' => 'Lone', 'admin-1' => 'Jean']);

        return new PostModerationActionToForumHandler(
            $actions,
            $this->cases,
            $gateway,
            $directory,
            new ModerationForumMessageFactory('https://archilan.fr'),
            new ModerationForumDelivery($this->forum, $this->cases, $this->logger),
            new MockClock('2026-09-27 10:00:05'),
        );
    }
}
