<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Exception\DiscordServerSanctionException;
use App\Community\Application\Handler\SyncDiscordBansHandler;
use App\Community\Application\Message\SyncDiscordBansMessage;
use App\Community\Application\Port\BannedMember;
use App\Community\Application\Port\DiscordBan;
use App\Community\Application\Port\MemberModerationGatewayInterface;
use App\Community\Application\Port\MemberModerationState;
use App\Community\Application\Query\CommunityAdminIdsQueryInterface;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Service\AccountModerationService;
use App\Community\Application\Support\ModerationForumMessageFactory;
use App\Community\Application\Support\Notifier;
use App\Community\Domain\Entity\DiscordBanNotice;
use App\Community\Domain\Entity\ModerationAction;
use App\Community\Domain\Repository\ContentReportRepositoryInterface;
use App\Community\Domain\Repository\ModerationActionRepositoryInterface;
use App\Tests\Unit\CatalogSync\RecordingLogger;
use App\Tests\Unit\Payments\RecordingMessageBus;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Clock\MockClock;

/**
 * Story 39.7: every five minutes, a ban posed on Discord bans the linked account on the site, and an unban on
 * Discord lifts a ban that came from Discord - never one posed from the site.
 */
final class SyncDiscordBansHandlerTest extends TestCase
{
    private RecordingDiscordServer $server;
    private RecordingModerationForum $forum;
    private InMemoryDiscordBanNoticeRepository $notices;
    private InMemoryDiscordBanBaselineRepository $baseline;
    private RecordingLogger $logger;
    /** @var list<ModerationAction> */
    private array $history = [];
    /** @var array<string, string> discord id => site user id */
    private array $links = ['d-11' => 'user-1', 'd-12' => 'user-2', 'd-99' => 'admin-1'];
    /** @var list<string> */
    private array $siteBanned = [];

    protected function setUp(): void
    {
        $this->server = new RecordingDiscordServer();
        $this->forum = new RecordingModerationForum();
        $this->notices = new InMemoryDiscordBanNoticeRepository();
        $this->baseline = new InMemoryDiscordBanBaselineRepository();
        $this->logger = new RecordingLogger();
    }

    public function testABanPosedOnDiscordBansTheLinkedAccount(): void
    {
        $this->server->banList = [new DiscordBan('d-11', 'lone', 'Raid du salon général')];
        $this->server->authors = ['d-11' => 'modo'];

        $this->sync();

        self::assertCount(1, $this->history);
        $ban = $this->history[0];
        self::assertSame(ModerationAction::ACTION_BAN, $ban->getAction());
        self::assertSame(ModerationAction::ACTOR_DISCORD, $ban->getActorId());
        self::assertSame('user-1', $ban->getTargetUserId());
        self::assertSame('Ban posé sur Discord par modo : Raid du salon général', $ban->getReason());
    }

    public function testTheSitesOwnBansAndMembersAlreadyBannedAreLeftAlone(): void
    {
        $this->server->banList = [
            new DiscordBan('d-11', 'lone', '[archilan.fr] Triche'),
            new DiscordBan('d-12', 'spam', null),
        ];
        $this->siteBanned = ['user-2'];

        $this->sync();
        $this->sync();

        self::assertSame([], $this->history);
    }

    public function testAnUnbanOnDiscordLiftsABanThatCameFromDiscord(): void
    {
        $this->siteBanned = ['user-1', 'user-2'];
        $this->history = [
            new ModerationAction('a-1', ModerationAction::ACTOR_DISCORD, 'user-1', ModerationAction::ACTION_BAN, 'Raid', new \DateTimeImmutable('2026-09-20')),
            new ModerationAction('a-2', 'admin-1', 'user-2', ModerationAction::ACTION_BAN, 'Triche', new \DateTimeImmutable('2026-09-20')),
        ];
        $this->server->banList = [];

        $this->sync();

        $lifts = array_values(array_filter($this->history, static fn (ModerationAction $a): bool => ModerationAction::ACTION_LIFT === $a->getAction()));
        self::assertCount(1, $lifts, 'a ban posed from the site is never lifted from Discord');
        self::assertSame('user-1', $lifts[0]->getTargetUserId());
        self::assertSame(ModerationAction::ACTOR_DISCORD, $lifts[0]->getActorId());
    }

    public function testNothingIsLiftedWhenTheListCouldNotBeRead(): void
    {
        $this->siteBanned = ['user-1'];
        $this->history = [new ModerationAction('a-1', ModerationAction::ACTOR_DISCORD, 'user-1', ModerationAction::ACTION_BAN, 'Raid', new \DateTimeImmutable('2026-09-20'))];
        $this->server->failListingWith = new DiscordServerSanctionException('Discord 503', transient: true);

        $this->sync();

        self::assertCount(1, $this->history);
        self::assertContains(['level' => 'warning', 'message' => 'moderation_discord_bans.not_read'], $this->logger->logs);
    }

    public function testAnUnlinkedAccountOrAnAdminIsToldToTheStaffOnce(): void
    {
        $this->server->banList = [new DiscordBan('d-50', 'inconnu', 'Pub'), new DiscordBan('d-99', 'jean', 'Test')];

        $this->sync();
        $this->sync();

        self::assertSame([], $this->history, 'nothing on the site: an admin is never sanctioned');
        self::assertCount(2, $this->forum->openedThreads, 'once per ban');
        self::assertSame('Discord : inconnu', $this->forum->openedThreads[0]['title']);
        self::assertContains(['name' => 'Sur le site', 'value' => 'aucun compte lié : rien n\'est appliqué'], $this->forum->openedThreads[0]['message']->fields);
        self::assertContains(['name' => 'Sur le site', 'value' => 'compte d\'un admin du site : jamais sanctionné'], $this->forum->openedThreads[1]['message']->fields);
        self::assertSame(DiscordBanNotice::REASON_ADMIN, ($this->notices->notices['d-99'] ?? null)?->getReason());
    }

    public function testABanThatComesBackIsToldAgain(): void
    {
        $this->server->banList = [new DiscordBan('d-50', 'inconnu', 'Pub')];
        $this->sync();
        $this->server->banList = [];
        $this->sync();
        $this->server->banList = [new DiscordBan('d-50', 'inconnu', 'Pub encore')];
        $this->sync();

        self::assertCount(2, $this->forum->openedThreads);
    }

    public function testAForumDownTellsTheStaffAtTheNextPass(): void
    {
        $this->server->banList = [new DiscordBan('d-50', 'inconnu', 'Pub')];
        $this->forum->failWith = new \App\Community\Application\Exception\ModerationForumDeliveryException('Discord 503', transient: true);

        $this->sync();
        self::assertSame([], $this->notices->notices);

        $this->forum->failWith = null;
        $this->sync();
        self::assertCount(1, $this->forum->openedThreads);
    }

    public function testTheBansPresentAtActivationAreShownToTheStaffNotApplied(): void
    {
        // Story 39.9: switching the synchronisation on never replays the server's history on the site.
        $this->baseline = new InMemoryDiscordBanBaselineRepository(taken: false);
        $this->siteBanned = ['user-2'];
        $this->history = [new ModerationAction('a-0', ModerationAction::ACTOR_DISCORD, 'user-2', ModerationAction::ACTION_BAN, 'Vieux', new \DateTimeImmutable('2026-01-01'))];
        $this->server->banList = [new DiscordBan('d-11', 'lone', 'Raid 2025'), new DiscordBan('d-50', 'inconnu', null), new DiscordBan('d-12', 'spam', '[archilan.fr] Triche')];

        $this->sync();

        self::assertCount(1, $this->history, 'no ban and no lift while the state is taken');
        self::assertCount(2, $this->forum->openedThreads, 'the site\'s own ban is not shown');
        self::assertContains(['name' => 'Sur le site', 'value' => 'ban antérieur à la synchronisation, à décider : rien n\'est appliqué sur le site'], $this->forum->openedThreads[0]['message']->fields);
        self::assertContains(['name' => 'Fiche', 'value' => 'https://archilan.fr/admin/utilisateurs/user-1'], $this->forum->openedThreads[0]['message']->fields);
        self::assertTrue($this->baseline->taken);

        // Next passes: those bans are never applied.
        $this->sync();
        self::assertCount(1, $this->history);
        self::assertCount(2, $this->forum->openedThreads);
    }

    public function testAStateTakenHalfwayIsTakenAgain(): void
    {
        $this->baseline = new InMemoryDiscordBanBaselineRepository(taken: false);
        $this->server->banList = [new DiscordBan('d-11', 'lone', 'Raid 2025')];
        $this->forum->failWith = new \App\Community\Application\Exception\ModerationForumDeliveryException('Discord 503', transient: true);

        $this->sync();
        self::assertFalse($this->baseline->taken);

        $this->forum->failWith = null;
        $this->sync();
        self::assertSame([], $this->history, 'still the initial state: nothing applied');
        self::assertTrue($this->baseline->taken);
    }

    public function testALiftNotYetAppliedOnDiscordIsNeverUndone(): void
    {
        // Story 39.9: the staff lifted on the site, Discord refused the unban. The member is not banned again.
        $lift = new ModerationAction('a-2', 'admin-1', 'user-1', ModerationAction::ACTION_LIFT, 'Appel accepté', new \DateTimeImmutable('2026-09-26'));
        $lift->recordServerSanction(ModerationAction::SERVER_FAILED);
        $this->history = [
            new ModerationAction('a-1', ModerationAction::ACTOR_DISCORD, 'user-1', ModerationAction::ACTION_BAN, 'Raid', new \DateTimeImmutable('2026-09-20')),
            $lift,
        ];
        $this->server->banList = [new DiscordBan('d-11', 'lone', 'Raid')];

        $this->sync();

        self::assertCount(2, $this->history);
        self::assertContains(['level' => 'warning', 'message' => 'moderation_discord_bans.lift_pending'], $this->logger->logs);
    }

    public function testABanAfterAnAppliedLiftIsANewBan(): void
    {
        $lift = new ModerationAction('a-2', 'admin-1', 'user-1', ModerationAction::ACTION_LIFT, 'Appel accepté', new \DateTimeImmutable('2026-09-26'));
        $lift->recordServerSanction(ModerationAction::SERVER_LIFTED);
        $this->history = [new ModerationAction('a-1', ModerationAction::ACTOR_DISCORD, 'user-1', ModerationAction::ACTION_BAN, 'Raid', new \DateTimeImmutable('2026-09-20')), $lift];
        $this->server->banList = [new DiscordBan('d-11', 'lone', 'Récidive')];

        $this->sync();

        self::assertCount(3, $this->history);
        self::assertSame(ModerationAction::ACTION_BAN, $this->history[2]->getAction());
    }

    public function testADiscordUnbanStillLiftsAfterAWarning(): void
    {
        $this->siteBanned = ['user-1'];
        $this->history = [
            new ModerationAction('a-1', ModerationAction::ACTOR_DISCORD, 'user-1', ModerationAction::ACTION_BAN, 'Raid', new \DateTimeImmutable('2026-09-20')),
            new ModerationAction('a-2', 'admin-1', 'user-1', ModerationAction::ACTION_WARN, 'Rappel', new \DateTimeImmutable('2026-09-21')),
        ];

        $this->sync();

        self::assertSame(ModerationAction::ACTION_LIFT, ($this->history[2] ?? null)?->getAction());
    }

    public function testNoBotNothingToRead(): void
    {
        $this->server = new RecordingDiscordServer(configured: false);
        $this->server->failListingWith = new DiscordServerSanctionException('must not be read');

        $this->sync();

        self::assertSame([], $this->logger->logs);
    }

    private function sync(): void
    {
        $gateway = self::createStub(MemberModerationGatewayInterface::class);
        $gateway->method('userIdForDiscordId')->willReturnCallback(fn (string $discordId): ?string => $this->links[$discordId] ?? null);
        $gateway->method('currentState')->willReturnCallback(fn (string $userId): MemberModerationState => new MemberModerationState(null, \in_array($userId, $this->siteBanned, true) ? '2026-09-20T00:00:00+00:00' : null, null));
        $gateway->method('currentlyBanned')->willReturnCallback(fn (): array => array_map(
            fn (string $userId): BannedMember => new BannedMember($userId, (string) array_search($userId, $this->links, true)),
            $this->siteBanned,
        ));
        $gateway->method('ban')->willReturnCallback(function (string $userId): bool {
            $this->siteBanned[] = $userId;

            return true;
        });
        $gateway->method('lift')->willReturnCallback(function (string $userId): bool {
            $this->siteBanned = array_values(array_diff($this->siteBanned, [$userId]));

            return true;
        });

        $actions = self::createStub(ModerationActionRepositoryInterface::class);
        $actions->method('save')->willReturnCallback(function (ModerationAction $action): void {
            $this->history[] = $action;
        });
        $actions->method('forTarget')->willReturnCallback(fn (string $userId, int $limit): array => array_slice(array_reverse(array_values(array_filter(
            $this->history,
            static fn (ModerationAction $a): bool => $a->getTargetUserId() === $userId,
        ))), 0, $limit));

        $admins = self::createStub(CommunityAdminIdsQueryInterface::class);
        $admins->method('adminUserIds')->willReturn(['admin-1']);

        $moderation = new AccountModerationService(
            $gateway,
            $actions,
            self::createStub(ContentReportRepositoryInterface::class),
            self::createStub(CommunityUserDirectoryQueryInterface::class),
            $admins,
            self::createStub(Notifier::class),
            new MockClock('2026-09-27 12:00:00'),
            new RecordingMessageBus(),
            $this->logger,
        );

        new SyncDiscordBansHandler(
            $this->server,
            $gateway,
            $admins,
            $actions,
            $moderation,
            $this->notices,
            $this->baseline,
            $this->forum,
            new ModerationForumMessageFactory('https://archilan.fr'),
            new MockClock('2026-09-27 12:00:00'),
            $this->logger,
        )(new SyncDiscordBansMessage());
    }
}
