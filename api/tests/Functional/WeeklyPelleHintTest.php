<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\SessionConfig\Application\Command\SetSessionConfigOverride;
use App\Sessions\Infrastructure\Double\SpyPelleHintGateway;
use App\Wallet\Application\Command\RecordPelleMovement;
use App\Wallet\Application\Command\RecordPelleMovementInput;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use App\WeeklyRuns\Domain\Entity\WeeklyEntry;
use App\WeeklyRuns\Domain\Entity\WeeklyRun;

/**
 * Story 41.8: hints bought with pelles in a weekly attempt.
 */
final class WeeklyPelleHintTest extends FunctionalTestCase
{
    private User $player;
    private string $runId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->player = $this->createUser('player@example.org', ['ROLE_USER'], 'Player');
        $now = new \DateTimeImmutable('2026-10-01T10:00:00+00:00');
        $run = new WeeklyRun(
            id: bin2hex(random_bytes(8)),
            templateId: 'template-1',
            weekYear: 2026,
            weekNumber: 40,
            seed: 'archilan-weekly-2026-40',
            status: WeeklyRun::STATUS_ACTIVE,
            startedAt: $now,
            createdAt: $now,
        );
        $this->entityManager->persist($run);
        $this->entityManager->flush();
        $this->runId = $run->getId();
        $spy = $this->spy();
        $spy->hints = [];
        $spy->failNext = false;
        $spy->refuseNext = null;
    }

    public function testAPlayerBuysAHintInTheirWeeklyWithGoldPelles(): void
    {
        $this->enable();
        $entryId = $this->entry($this->player, launched: true);
        $this->gold(50);
        $this->loginAs($this->player);

        $this->client->jsonRequest('POST', $this->url($entryId), ['kind' => 'item', 'itemName' => 'Grappin', 'requestId' => 'r1']);

        self::assertResponseIsSuccessful();
        self::assertSame(['paidWith' => 'gold', 'price' => 20, 'balanceAfter' => 30, 'alreadyBought' => false], $this->decodedJsonResponse());
        self::assertSame(['ext-session-1/1/item:Grappin'], $this->spy()->hints, 'the attempt\'s own bridge');
    }

    public function testTheOfferFollowsTheWeeklyConfig(): void
    {
        $entryId = $this->entry($this->player, launched: true);
        $this->gold(50);
        $this->loginAs($this->player);

        $this->client->request('GET', $this->url($entryId));
        self::assertSame(['enabled' => false, 'itemPrice' => 20, 'locationPrice' => 10, 'eventBalance' => null, 'goldBalance' => 50], $this->decodedJsonResponse());

        $this->client->jsonRequest('POST', $this->url($entryId), ['kind' => 'item', 'itemName' => 'Grappin', 'requestId' => 'r1']);
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnAttemptNotLaunchedOrFinishedRefuses(): void
    {
        $this->enable();
        $this->gold(50);
        $this->loginAs($this->player);

        $this->client->jsonRequest('POST', $this->url($this->entry($this->player, launched: false)), ['kind' => 'item', 'itemName' => 'Grappin', 'requestId' => 'r1']);
        self::assertResponseStatusCodeSame(409);

        $this->client->jsonRequest('POST', $this->url($this->entry($this->player, launched: true, finished: true)), ['kind' => 'item', 'itemName' => 'Grappin', 'requestId' => 'r2']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testSomeoneElsesAttemptIsForbidden(): void
    {
        $this->enable();
        $entryId = $this->entry($this->createUser('other@example.org', ['ROLE_USER'], 'Other'), launched: true);
        $this->loginAs($this->player);

        $this->client->jsonRequest('POST', $this->url($entryId), ['kind' => 'item', 'itemName' => 'Grappin', 'requestId' => 'r1']);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->entityManager->getRepository(PelleMovement::class)->findBy(['reason' => PelleReason::HintPurchase]));
    }

    private function enable(): void
    {
        $set = self::getContainer()->get(SetSessionConfigOverride::class);
        self::assertInstanceOf(SetSessionConfigOverride::class, $set);
        $set->execute('template-1', ['pelleHints' => true]);
    }

    private function entry(User $user, bool $launched, bool $finished = false): string
    {
        $at = new \DateTimeImmutable('2026-10-01T11:00:00+00:00');
        $entry = new WeeklyEntry(
            bin2hex(random_bytes(16)),
            $this->runId,
            $user->getId(),
            1,
            $at,
            $at,
            externalSessionId: $launched ? 'ext-session-1' : null,
            launchedAt: $launched ? $at : null,
            goalReachedAt: $finished ? $at->modify('+1 hour') : null,
            bridgePort: $launched ? 45000 : null,
        );
        $this->entityManager->persist($entry);
        $this->entityManager->flush();

        return $entry->getId();
    }

    private function url(string $entryId): string
    {
        return sprintf('/api/v1/weekly-runs/%s/entries/%s/slots/1/pelle-hints', $this->runId, $entryId);
    }

    private function gold(int $amount): void
    {
        $record = self::getContainer()->get(RecordPelleMovement::class);
        self::assertInstanceOf(RecordPelleMovement::class, $record);
        $record->record(new RecordPelleMovementInput($this->player->getId(), $amount, PelleKind::Gold, null, PelleReason::AdminCredit, 'Crédit de test', null, null, true));
    }

    private function spy(): SpyPelleHintGateway
    {
        $spy = self::getContainer()->get(SpyPelleHintGateway::class);
        self::assertInstanceOf(SpyPelleHintGateway::class, $spy);

        return $spy;
    }
}
