<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Sessions\Application\Service\SlotDurationEstimate;
use App\Sessions\Application\Support\PaceSource;
use App\Sessions\Domain\Entity\Session;
use App\Sessions\Domain\Entity\SessionSlot;
use App\WeeklyRuns\Domain\Entity\WeeklyEntry;
use App\WeeklyRuns\Domain\Entity\WeeklyRun;
use App\WeeklyRuns\Domain\Entity\WeeklyTemplate;

/**
 * Story 56.2: the paces our finished games showed - session slots from the session's launch to the goal, weekly
 * entries over their completion time - and the admin's estimate that stands in below three slots.
 */
final class GamePaceTest extends FunctionalTestCase
{
    private const string START = '2026-10-01T10:00:00+00:00';

    public function testSessionSlotsAndWeeklyEntriesMakeTheMeasuredPace(): void
    {
        $game = $this->createGame('Hollow Knight', 'hollow-knight');
        // 120 checks in 2 h, 90 in 3 h: 60 and 30 an hour.
        $this->slotAtGoal('s-1', $game->getId(), 'member-a', 120, '+2 hours');
        $this->slotAtGoal('s-2', $game->getId(), 'member-b', 90, '+3 hours');
        // A slot without its goal, and one "at goal" after ten seconds, are not paces.
        $this->slotAtGoal('s-3', $game->getId(), 'member-c', 500, null);
        $this->slotAtGoal('s-4', $game->getId(), 'member-c', 500, '+10 seconds');
        // A weekly entry: 100 checks in 2 h, 50 an hour.
        $this->weeklyAtGoal($game->getId(), 'member-d', 100, 7200);

        $duration = $this->estimate()->forSlot($game->getId(), [], 250);

        self::assertSame(PaceSource::Measured, $duration->source);
        self::assertSame(3, $duration->slots);
        self::assertEqualsWithDelta(50.0, $duration->checksPerHour, 0.01);
        self::assertEqualsWithDelta(5.0, $duration->hours, 0.01);
        // member-a played at 60 against 50: 1 + 0.2 x 1 / 4.
        self::assertEqualsWithDelta(1.05, $this->estimate()->playerFactor('member-a'), 0.001);
    }

    public function testTheAdminEstimateStandsInBelowThreeSlots(): void
    {
        $game = $this->createGame('Tunic', 'tunic');
        $this->slotAtGoal('s-1', $game->getId(), 'member-a', 120, '+2 hours');
        $admin = $this->createUser('admin@example.com', ['ROLE_ADMIN']);
        $this->loginAs($admin);

        $this->client->jsonRequest('PATCH', '/api/v1/admin/games/'.$game->getId().'/estimated-pace', ['estimatedChecksPerHour' => 40]);

        self::assertResponseIsSuccessful();
        $payload = $this->decodedJsonResponse();
        self::assertIsArray($payload['data'] ?? null);
        self::assertSame(40, $payload['data']['estimatedChecksPerHour']);

        $duration = $this->estimate()->forSlot($game->getId(), [], 200);
        self::assertSame(PaceSource::Admin, $duration->source);
        self::assertEqualsWithDelta(5.0, $duration->hours, 0.01);
    }

    public function testTheAdminEstimateIsBoundedAndCanBeCleared(): void
    {
        $game = $this->createGame('Tunic', 'tunic');
        $this->loginAs($this->createUser('admin@example.com', ['ROLE_ADMIN']));
        $url = '/api/v1/admin/games/'.$game->getId().'/estimated-pace';

        $this->client->jsonRequest('PATCH', $url, ['estimatedChecksPerHour' => 0]);
        self::assertResponseStatusCodeSame(422);
        $this->client->jsonRequest('PATCH', $url, ['estimatedChecksPerHour' => '40']);
        self::assertResponseStatusCodeSame(422);

        $this->client->jsonRequest('PATCH', $url, ['estimatedChecksPerHour' => 40]);
        self::assertResponseIsSuccessful();
        $this->client->jsonRequest('PATCH', $url, ['estimatedChecksPerHour' => null]);
        self::assertResponseIsSuccessful();
        $payload = $this->decodedJsonResponse();
        self::assertIsArray($payload['data'] ?? null);
        self::assertNull($payload['data']['estimatedChecksPerHour']);
    }

    public function testAMemberCannotSetThePace(): void
    {
        $game = $this->createGame('Tunic', 'tunic');
        $this->loginAs($this->createUser('member@example.com'));

        $this->client->jsonRequest('PATCH', '/api/v1/admin/games/'.$game->getId().'/estimated-pace', ['estimatedChecksPerHour' => 40]);

        self::assertResponseStatusCodeSame(403);
    }

    private function estimate(): SlotDurationEstimate
    {
        $estimate = self::getContainer()->get(SlotDurationEstimate::class);
        self::assertInstanceOf(SlotDurationEstimate::class, $estimate);

        return $estimate;
    }

    /** A personal-run slot: its registration id is the member's id. */
    private function slotAtGoal(string $sessionId, string $gameId, string $memberId, int $checks, ?string $goalAfter): void
    {
        $start = new \DateTimeImmutable(self::START);
        $this->entityManager->persist(Session::createRunning($sessionId, 'run-'.$sessionId, 'localhost', 38281, null, null, $start));
        $slot = SessionSlot::create('slot-'.$sessionId, $sessionId, $memberId, $gameId, 'P'.$sessionId, 0);
        $slot->recordProgress($checks, 0);
        if (null !== $goalAfter) {
            $slot->recordGoal($start->modify($goalAfter));
        }
        $this->entityManager->persist($slot);
        $this->entityManager->flush();
    }

    private function weeklyAtGoal(string $gameId, string $memberId, int $checks, int $seconds): void
    {
        $now = new \DateTimeImmutable(self::START);
        $template = new WeeklyTemplate(
            id: 'template-1',
            gameId: $gameId,
            yamlConfig: "name: ArchiLAN\n",
            name: 'Hebdo',
            maxAttempts: null,
            isActive: true,
            createdAt: $now,
            updatedAt: $now,
        );
        $run = new WeeklyRun(
            id: 'weekly-1',
            templateId: 'template-1',
            weekYear: 2026,
            weekNumber: 40,
            seed: 'seed',
            status: WeeklyRun::STATUS_FINISHED,
            startedAt: $now,
            createdAt: $now,
        );
        $entry = new WeeklyEntry('entry-1', 'weekly-1', $memberId, 1, $now, $now, launchedAt: $now);
        $entry->recordGoal($now->modify('+'.$seconds.' seconds'), $seconds, $checks, 0);
        $this->entityManager->persist($template);
        $this->entityManager->persist($run);
        $this->entityManager->persist($entry);
        $this->entityManager->flush();
    }
}
