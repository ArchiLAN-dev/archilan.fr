<?php

declare(strict_types=1);

namespace App\Tests\Unit\Community;

use App\Community\Application\Query\CommunityDirectoryQueryInterface;
use App\Community\Application\Query\CommunityOverviewQuery;
use App\Community\Application\Query\CommunityPresenceQueryInterface;
use App\Community\Application\Query\CommunityUserDirectoryQueryInterface;
use App\Community\Application\Query\RecentAchievementGrantsQueryInterface;
use App\Community\Application\Support\AchievementImageUrlResolver;
use App\Community\Domain\Entity\AchievementDefinition;
use App\Community\Domain\Repository\AchievementDefinitionRepositoryInterface;
use App\Events\Domain\Repository\EventRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunParticipantRepositoryInterface;
use App\PersonalRuns\Domain\Repository\RunRepositoryInterface;
use App\Sessions\Application\Query\ViewableSessionsQuery;
use App\Sessions\Application\Support\SessionRecapAudience;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Repository\SessionRepositoryInterface;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use PHPUnit\Framework\TestCase;

final class CommunityOverviewQueryTest extends TestCase
{
    public function testNamesTheGameOnlyForASessionTheViewerMaySee(): void
    {
        // Neither session resolves to an event or a run -> SessionRecapAudience denies both, so the
        // player still shows as playing but the game is withheld. This is the private-run case.
        $query = $this->makeQuery(
            playing: [
                ['userId' => 'u1', 'sessionId' => 'sess-private', 'game' => 'Hollow Knight'],
            ],
            grants: [],
            sessions: ['sess-private'],
        );

        $playing = $query->forViewer(null)['playingNow'];

        self::assertCount(1, $playing);
        self::assertSame('alice', $playing[0]['slug']);
        self::assertNull($playing[0]['game'], 'a session the viewer may not see must not name its game');
    }

    public function testDropsAPlayerWithoutAListableCard(): void
    {
        // cards() is the listable read model: no card means nothing to link to.
        $query = $this->makeQuery(
            playing: [
                ['userId' => 'u1', 'sessionId' => 'sess-1', 'game' => null],
                ['userId' => 'ghost', 'sessionId' => 'sess-1', 'game' => null],
            ],
            grants: [],
            sessions: [],
        );

        $playing = $query->forViewer(null)['playingNow'];

        self::assertCount(1, $playing);
        self::assertSame('alice', $playing[0]['slug']);
    }

    public function testPresentsRecentAchievementsWithTheirDefinition(): void
    {
        $query = $this->makeQuery(
            playing: [],
            grants: [
                ['userId' => 'u1', 'achievementKey' => 'first_run', 'unlockedAt' => '2026-08-01T10:00:00+00:00'],
            ],
            sessions: [],
        );

        $recent = $query->forViewer(null)['recentAchievements'];

        self::assertCount(1, $recent);
        self::assertSame('first_run', $recent[0]['achievementKey']);
        self::assertSame('Première run', $recent[0]['name']);
        self::assertSame('alice', $recent[0]['slug']);
        self::assertSame('2026-08-01T10:00:00+00:00', $recent[0]['unlockedAt']);
    }

    public function testDropsAGrantWhoseDefinitionNoLongerExists(): void
    {
        $query = $this->makeQuery(
            playing: [],
            grants: [
                ['userId' => 'u1', 'achievementKey' => 'deleted_key', 'unlockedAt' => '2026-08-01T10:00:00+00:00'],
                ['userId' => 'u1', 'achievementKey' => 'first_run', 'unlockedAt' => '2026-07-01T10:00:00+00:00'],
            ],
            sessions: [],
        );

        $recent = $query->forViewer(null)['recentAchievements'];

        self::assertCount(1, $recent);
        self::assertSame('first_run', $recent[0]['achievementKey']);
    }

    public function testReportsTheListableMemberCount(): void
    {
        $query = $this->makeQuery(playing: [], grants: [], sessions: []);

        self::assertSame(42, $query->forViewer(null)['memberCount']);
    }

    /**
     * @param list<array{userId: string, sessionId: string, game: string|null}>       $playing
     * @param list<array{userId: string, achievementKey: string, unlockedAt: string}> $grants
     * @param list<string>                                                            $sessions ids the session repo knows about
     */
    private function makeQuery(array $playing, array $grants, array $sessions): CommunityOverviewQuery
    {
        $directory = self::createStub(CommunityDirectoryQueryInterface::class);
        $directory->method('listableMemberCount')->willReturn(42);

        $presence = self::createStub(CommunityPresenceQueryInterface::class);
        $presence->method('playingNow')->willReturn($playing);

        $recentGrants = self::createStub(RecentAchievementGrantsQueryInterface::class);
        $recentGrants->method('recent')->willReturn($grants);

        // Only u1 is listable; "ghost" has no card.
        $cards = self::createStub(CommunityUserDirectoryQueryInterface::class);
        $cards->method('cards')->willReturn([
            'u1' => ['userId' => 'u1', 'slug' => 'alice', 'displayName' => 'Alice', 'avatarUrl' => null],
        ]);

        $definitions = self::createStub(AchievementDefinitionRepositoryInterface::class);
        $definitions->method('all')->willReturn([
            new AchievementDefinition(
                'def-1',
                'first_run',
                'Première run',
                'Terminer une première run.',
                [],
                true,
                0,
                new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
                new \DateTimeImmutable('2026-01-01T00:00:00+00:00'),
            ),
        ]);

        $sessionRepo = self::createStub(SessionRepositoryInterface::class);
        $sessionRepo->method('findByIds')->willReturn(array_map(
            static fn (string $id): Session => Session::create($id, 'evt-unknown', new \DateTimeImmutable('2026-08-01T09:00:00+00:00')),
            $sessions,
        ));

        // Neither an event nor a run resolves -> SessionRecapAudience::canView() is false for every
        // session, which is the "do not name the game" branch under test.
        $audience = new SessionRecapAudience(
            self::createStub(EventRepositoryInterface::class),
            self::createStub(RunRepositoryInterface::class),
            self::createStub(RunParticipantRepositoryInterface::class),
        );

        return new CommunityOverviewQuery(
            $directory,
            $presence,
            $recentGrants,
            $cards,
            $definitions,
            new AchievementImageUrlResolver(self::createStub(MinioStorageInterface::class), 'media', 3600),
            new ViewableSessionsQuery($sessionRepo, $audience),
        );
    }
}
