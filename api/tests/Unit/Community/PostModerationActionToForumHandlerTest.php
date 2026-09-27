<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Exception\DiscordServerSanctionException;
use App\Community\Application\Exception\DiscordServerTemporarilyUnavailableException;
use App\Community\Application\Exception\MemberDirectMessageException;
use App\Community\Application\Exception\MemberDirectMessageTemporarilyUnavailableException;
use App\Community\Application\Exception\ModerationForumDeliveryException;
use App\Community\Application\Exception\ModerationForumTemporarilyUnavailableException;
use App\Community\Application\Handler\PostModerationActionToForumHandler;
use App\Community\Application\Message\PostModerationActionToForumJob;
use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Application\Port\MemberModerationState;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Support\MemberDirectMessenger;
use App\Community\Application\Support\ModerationForumDelivery;
use App\Community\Application\Support\ModerationForumMessageFactory;
use App\Community\Domain\Entity\ModerationAction;
use App\Community\Domain\Entity\ModerationCase;
use App\Community\Domain\Entity\ModerationCaseMessage;
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
    private RecordingMemberDirectMessages $dms;
    private RecordingDiscordServer $server;
    private ?string $discordId = '123456789';
    private ?string $suspendedUntil = null;
    private bool $banned = true;
    private RecordingLogger $logger;

    protected function setUp(): void
    {
        $this->cases = new InMemoryModerationCaseRepository();
        $this->forum = new RecordingModerationForum();
        $this->dms = new RecordingMemberDirectMessages();
        $this->server = new RecordingDiscordServer($this->dms);
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

    public function testWithoutAForumTheCaseAndTheDirectMessageStillHappen(): void
    {
        $this->forum = new RecordingModerationForum(configured: false);

        $this->handle($this->action('a-1', ModerationAction::ACTION_BAN, 'Triche'));

        self::assertSame([], $this->forum->openedThreads);
        self::assertNotNull($this->cases->findByTargetUserId('user-1'), 'story 39.4: the case carries the DM channel');
        self::assertCount(1, $this->dms->sent);
    }

    public function testTheMemberIsToldInPrivateAndTheForumSaysSo(): void
    {
        $action = $this->action('a-1', ModerationAction::ACTION_BAN, 'Triche');

        $this->handle($action);

        self::assertCount(1, $this->dms->sent);
        $dm = $this->dms->sent[0]['message'];
        self::assertSame('Ban sur ArchiLAN', $dm->title);
        self::assertSame('Triche', $dm->description);
        self::assertSame(ModerationCaseMessage::DM_SENT, $action->getDiscordDmStatus());
        self::assertSame('dm-123456789', $this->cases->findByTargetUserId('user-1')?->getDirectMessageChannelId());
        self::assertContains(['name' => 'Message privé Discord', 'value' => 'envoyé'], $this->forum->openedThreads[0]['message']->fields);
    }

    public function testASiteBanBansOnTheServerAfterTheDirectMessage(): void
    {
        $action = $this->action('a-1', ModerationAction::ACTION_BAN, 'Triche');

        $this->handle($action);

        self::assertSame([['discordUserId' => '123456789', 'reason' => 'Triche', 'directMessagesBefore' => 1]], $this->server->bans, 'banned once told, while the bot still shares the server');
        self::assertSame(ModerationAction::SERVER_BANNED, $action->getDiscordServerStatus());
        self::assertContains(['name' => 'Serveur Discord', 'value' => 'banni'], $this->forum->openedThreads[0]['message']->fields);
    }

    public function testABanThatCameFromDiscordIsNotPosedAgain(): void
    {
        // Story 39.7: banning again would overwrite the reason with the site's prefix, and the ban would then
        // pass for one of the site's.
        $action = new ModerationAction('a-1', ModerationAction::ACTOR_DISCORD, 'user-1', ModerationAction::ACTION_BAN, 'Raid', new \DateTimeImmutable('2026-09-27 10:00:00'));
        $this->actions['a-1'] = $action;

        $this->handle($action);

        self::assertSame([], $this->server->bans);
        self::assertSame(ModerationAction::SERVER_BANNED, $action->getDiscordServerStatus());
        self::assertContains(['name' => 'Modérateur', 'value' => 'Discord'], $this->forum->openedThreads[0]['message']->fields);
    }

    public function testALiftUnbansAndEndsTheTimeout(): void
    {
        $action = $this->action('a-1', ModerationAction::ACTION_LIFT, 'Appel accepté');

        $this->handle($action);

        self::assertSame(['123456789'], $this->server->unbans);
        self::assertSame(['123456789'], $this->server->clearedTimeouts, 'story 39.6: a lift ends a suspension too');
        self::assertSame(ModerationAction::SERVER_LIFTED, $action->getDiscordServerStatus());
        self::assertContains(['name' => 'Serveur Discord', 'value' => 'sanction levée sur le serveur'], $this->forum->openedThreads[0]['message']->fields);
    }

    public function testASuspensionTimesTheMemberOutUntilItEnds(): void
    {
        $this->suspendedUntil = '2026-10-04T10:00:00+00:00';
        $action = $this->action('a-1', ModerationAction::ACTION_SUSPEND, 'Comportement');

        $this->handle($action);

        self::assertSame([['discordUserId' => '123456789', 'until' => '2026-10-04T10:00:00+00:00', 'reason' => 'Comportement']], $this->server->timeouts);
        self::assertSame(ModerationAction::SERVER_TIMED_OUT, $action->getDiscordServerStatus());
        self::assertContains(['name' => 'Serveur Discord', 'value' => 'exclu temporairement'], $this->forum->openedThreads[0]['message']->fields);
        self::assertSame([], $this->server->bans, 'no ban for a suspension');
    }

    public function testALongSuspensionIsCutToDiscordsTwentyEightDays(): void
    {
        $this->suspendedUntil = '2027-01-01T00:00:00+00:00';

        $this->handle($this->action('a-1', ModerationAction::ACTION_SUSPEND, 'Récidive'));

        self::assertSame('2026-10-25T09:59:05+00:00', $this->server->timeouts[0]['until'], '28 days minus a minute from now; the daily job extends it');
    }

    public function testAMemberOffTheServerIsNoError(): void
    {
        $this->suspendedUntil = '2026-10-04T10:00:00+00:00';
        $this->server->absent = ['123456789'];
        $action = $this->action('a-1', ModerationAction::ACTION_SUSPEND, 'Comportement');

        $this->handle($action);

        self::assertSame(ModerationAction::SERVER_NOT_MEMBER, $action->getDiscordServerStatus());
        self::assertContains(['name' => 'Serveur Discord', 'value' => 'pas sur le serveur'], $this->forum->openedThreads[0]['message']->fields);
    }

    public function testAWarningLeavesTheServerAlone(): void
    {
        $action = $this->action('a-1', ModerationAction::ACTION_WARN, 'Spam');

        $this->handle($action);

        self::assertSame([], $this->server->bans);
        self::assertNull($action->getDiscordServerStatus());
        foreach ($this->forum->openedThreads[0]['message']->fields as $field) {
            self::assertNotSame('Serveur Discord', $field['name']);
        }
    }

    public function testAnUnlinkedAccountIsNotBannedOnDiscord(): void
    {
        $this->discordId = null;
        $action = $this->action('a-1', ModerationAction::ACTION_BAN, 'Triche');

        $this->handle($action);

        self::assertSame([], $this->server->bans);
        self::assertSame(ModerationAction::SERVER_NOT_LINKED, $action->getDiscordServerStatus());
        self::assertContains(['name' => 'Serveur Discord', 'value' => 'compte Discord non lié'], $this->forum->openedThreads[0]['message']->fields);
    }

    public function testAMissingPermissionIsReportedNotRetried(): void
    {
        $this->server->failWith = new DiscordServerSanctionException('Discord 403: Missing Permissions');
        $action = $this->action('a-1', ModerationAction::ACTION_BAN, 'Triche');

        $this->handle($action);

        self::assertSame(ModerationAction::SERVER_FAILED, $action->getDiscordServerStatus());
        self::assertContains(['name' => 'Serveur Discord', 'value' => 'échec (permission ou rôle du bot)'], $this->forum->openedThreads[0]['message']->fields);
        self::assertContains(['level' => 'warning', 'message' => 'moderation_server.not_applied'], $this->logger->logs);
    }

    public function testAPassingServerFailureIsRetriedWithoutASecondDirectMessage(): void
    {
        $this->server->failWith = new DiscordServerSanctionException('Discord 503', transient: true);
        $action = $this->action('a-1', ModerationAction::ACTION_BAN, 'Triche');
        try {
            $this->handle($action);
            self::fail('retried');
        } catch (DiscordServerTemporarilyUnavailableException) {
        }
        self::assertNull($action->getDiscordServerStatus());
        self::assertSame([], $this->forum->openedThreads, 'the forum waits for the whole picture');

        $this->server->failWith = null;
        $this->handle($action);

        self::assertCount(1, $this->dms->sent);
        self::assertCount(1, $this->server->bans);
        self::assertSame(ModerationAction::SERVER_BANNED, $action->getDiscordServerStatus());
    }

    public function testABanAlreadyLiftedWhenItsJobRunsIsNotApplied(): void
    {
        // Story 39.9: the ban's job was retried after the lift's. Applying it now would ban a member the site
        // has already let back in, and reopen a closed case.
        $this->banned = false;
        $case = ModerationCase::open('user-1', new \DateTimeImmutable('2026-09-20'));
        $case->close(new \DateTimeImmutable('2026-09-27 10:00:02'));
        $this->cases->save($case);
        $action = $this->action('a-1', ModerationAction::ACTION_BAN, 'Triche');

        $this->handle($action);

        self::assertSame([], $this->server->bans);
        self::assertSame([], $this->dms->sent, 'no "you are banned" message for a lifted ban');
        self::assertSame(ModerationAction::SERVER_SUPERSEDED, $action->getDiscordServerStatus());
        self::assertSame(ModerationCaseMessage::DM_SUPERSEDED, $action->getDiscordDmStatus());
        self::assertFalse($case->isOpen());
    }

    public function testASuspensionOverWhenItsJobRunsIsNotApplied(): void
    {
        $this->banned = false;
        $this->suspendedUntil = '2026-09-27T09:00:00+00:00';
        $action = $this->action('a-1', ModerationAction::ACTION_SUSPEND, 'Comportement');

        $this->handle($action);

        self::assertSame([], $this->server->timeouts);
        self::assertSame(ModerationAction::SERVER_SUPERSEDED, $action->getDiscordServerStatus());
    }

    public function testAPassingDirectMessageFailureDoesNotHoldTheServerBack(): void
    {
        // Story 39.9: the sanction on the server comes first; the message is retried after.
        $this->dms->failWith = new MemberDirectMessageException('Discord 429', transient: true);
        $action = $this->action('a-1', ModerationAction::ACTION_BAN, 'Triche');

        try {
            $this->handle($action);
            self::fail('the message must be retried');
        } catch (MemberDirectMessageTemporarilyUnavailableException) {
        }

        self::assertCount(1, $this->server->bans);
        self::assertSame(ModerationAction::SERVER_BANNED, $action->getDiscordServerStatus());
        self::assertNull($action->getDiscordDmStatus());

        $this->dms->failWith = null;
        $this->handle($action);
        self::assertCount(1, $this->server->bans, 'not banned twice');
        self::assertCount(1, $this->dms->sent);
    }

    public function testClosedPrivateMessagesAreReported(): void
    {
        $this->dms->failWith = new MemberDirectMessageException('Discord 403: Cannot send messages to this user');
        $action = $this->action('a-1', ModerationAction::ACTION_WARN, 'Spam');

        $this->handle($action);

        self::assertSame(ModerationCaseMessage::DM_FAILED, $action->getDiscordDmStatus());
        self::assertContains(['name' => 'Message privé Discord', 'value' => 'impossible (MP fermés ou serveur quitté)'], $this->forum->openedThreads[0]['message']->fields);
    }

    public function testARetryAfterAForumFailureNeverSendsTheDirectMessageTwice(): void
    {
        $action = $this->action('a-1', ModerationAction::ACTION_BAN, 'Triche');
        $this->forum->failWith = new ModerationForumDeliveryException('Discord 503', transient: true);
        try {
            $this->handle($action);
            self::fail('retried');
        } catch (ModerationForumTemporarilyUnavailableException) {
        }

        $this->forum->failWith = null;
        $this->handle($action);

        self::assertCount(1, $this->dms->sent);
        self::assertCount(1, $this->forum->openedThreads);
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
        $gateway->method('discordIdOf')->willReturn($this->discordId);
        $gateway->method('currentState')->willReturnCallback(fn (): MemberModerationState => new MemberModerationState($this->suspendedUntil, $this->banned ? '2026-09-27T10:00:00+00:00' : null, null));

        $directory = self::createStub(CommunityUserDirectoryQueryInterface::class);
        $directory->method('namesFor')->willReturn(['user-1' => 'Lone', 'admin-1' => 'Jean']);

        return new PostModerationActionToForumHandler(
            $actions,
            $this->cases,
            $gateway,
            $directory,
            new ModerationForumMessageFactory('https://archilan.fr'),
            new MemberDirectMessenger($this->dms, $this->logger),
            $this->server,
            new ModerationForumDelivery($this->forum, $this->cases, $this->logger),
            new MockClock('2026-09-27 10:00:05'),
            $this->logger,
        );
    }
}
