<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Identity\Domain\Entity\User;
use App\PersonalRuns\Domain\Entity\Run;
use App\Wallet\Application\Command\ReconcileRunBronze;
use App\Wallet\Application\Command\RecordPelleMovement;
use App\Wallet\Application\Command\RecordPelleMovementInput;
use App\Wallet\Application\Message\ReconcileRunBronzeJob;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Repository\PelleMovementRepositoryInterface;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;

/**
 * Story 41.36: a private run's bronze pelles live as long as the run - burned when it ends, given back when a
 * cancelled run is restored.
 */
final class RunBronzeTest extends FunctionalTestCase
{
    private User $owner;

    private User $bob;

    private Run $run;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = $this->createUser('owner@example.org', ['ROLE_USER'], 'Owner', 'owner');
        $this->bob = $this->createUser('bob@example.org', ['ROLE_USER'], 'Bob', 'bob');
        $this->run = Run::create($this->owner->getId(), 'Ma run', new \DateTimeImmutable());
        $this->run->attachSession('session-1');
        $this->setStatus(Run::STATUS_IDLE);
        $this->entityManager->persist($this->run);
        $this->entityManager->flush();
    }

    public function testBronzeIsKeptPerRun(): void
    {
        $other = Run::create($this->owner->getId(), 'Autre run', new \DateTimeImmutable());
        $this->entityManager->persist($other);
        $this->entityManager->flush();

        $this->credit($this->bob, 30);
        $this->credit($this->bob, 5, $other->getId());

        self::assertSame(30, $this->balance($this->bob));
        self::assertSame(5, $this->balance($this->bob, $other->getId()));
        self::assertSame(0, $this->ledger()->balance($this->bob->getId(), PelleKind::Gold, null), 'bronze is never gold');
    }

    public function testBronzeWithoutAnExistingRunIsRefused(): void
    {
        $this->expectExceptionMessage('Partie introuvable.');

        $this->recorder()->record(new RecordPelleMovementInput($this->bob->getId(), 10, PelleKind::Bronze, null, PelleReason::BronzeStart, 'Départ', null, null, runId: 'no-such-run'));
    }

    public function testCancellingTheRunBurnsItsBronze(): void
    {
        $this->credit($this->owner, 12);
        $this->credit($this->bob, 30);
        $this->client->disableReboot();
        $this->loginAs($this->owner);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$this->run->getId().'/archive');
        self::assertResponseIsSuccessful();
        $this->runJobs();

        self::assertSame(0, $this->balance($this->owner));
        self::assertSame(0, $this->balance($this->bob));
        $burns = $this->movements(PelleReason::BronzeExpired);
        self::assertSame([-12, -30], array_map(static fn (PelleMovement $m): int => $m->getAmount(), $burns));
        self::assertSame('Fin de « Ma run »', $burns[0]->getLabel());
    }

    public function testRestoringGivesBackWhatBurnedEvenTwice(): void
    {
        $this->credit($this->bob, 30);
        $this->client->disableReboot();
        $this->loginAs($this->owner);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$this->run->getId().'/archive');
        $this->runJobs();
        $this->client->jsonRequest('POST', '/api/v1/runs/'.$this->run->getId().'/unarchive');
        self::assertResponseIsSuccessful();
        $this->runJobs();
        self::assertSame(30, $this->balance($this->bob));

        $this->credit($this->bob, 4, null, 'second-life');
        $this->client->jsonRequest('POST', '/api/v1/runs/'.$this->run->getId().'/archive');
        $this->runJobs();
        self::assertSame(0, $this->balance($this->bob), 'the second burn is not refused by the first one\'s key');
        $this->client->jsonRequest('POST', '/api/v1/runs/'.$this->run->getId().'/unarchive');
        $this->runJobs();

        self::assertSame(34, $this->balance($this->bob));
        self::assertCount(2, $this->movements(PelleReason::BronzeExpired));
        self::assertCount(2, $this->movements(PelleReason::BronzeRestored));
    }

    public function testCancelledThenRestoredBeforeTheWorkerMovesNothing(): void
    {
        $this->credit($this->bob, 30);
        $this->client->disableReboot();
        $this->loginAs($this->owner);

        $this->client->jsonRequest('POST', '/api/v1/runs/'.$this->run->getId().'/archive');
        $this->client->jsonRequest('POST', '/api/v1/runs/'.$this->run->getId().'/unarchive');
        $this->runJobs();

        self::assertSame(30, $this->balance($this->bob));
        self::assertSame([], $this->movements(PelleReason::BronzeExpired));
    }

    public function testACompletedRunBurnsAndAPausedOneDoesNot(): void
    {
        $this->credit($this->bob, 30);

        $this->reconcile();
        self::assertSame(30, $this->balance($this->bob), 'idle is a pause, not an end');

        $this->setStatus(Run::STATUS_COMPLETED);
        $this->entityManager->flush();
        $this->reconcile();
        $this->reconcile();

        self::assertSame(0, $this->balance($this->bob));
        self::assertCount(1, $this->movements(PelleReason::BronzeExpired), 'safe to run twice');
    }

    public function testABannedPlayersBronzeBurnsToo(): void
    {
        $this->credit($this->bob, 30);
        $this->bob->ban('Triche', new \DateTimeImmutable('-1 day'));
        $this->setStatus(Run::STATUS_CANCELLED);
        $this->entityManager->flush();

        $this->reconcile();

        self::assertSame(0, $this->balance($this->bob));
    }

    public function testDeletingTheRunBurnsItsBronzeFirst(): void
    {
        $this->credit($this->bob, 30);
        $runId = $this->run->getId();
        $this->loginAs($this->owner);

        $this->client->jsonRequest('DELETE', '/api/v1/runs/'.$runId);
        self::assertResponseIsSuccessful();

        self::assertSame(0, $this->ledger()->balance($this->bob->getId(), PelleKind::Bronze, null, $runId));
    }

    private function setStatus(string $status): void
    {
        new \ReflectionProperty(Run::class, 'status')->setValue($this->run, $status);
    }

    private function credit(User $member, int $amount, ?string $runId = null, string $key = 'start'): void
    {
        $runId ??= $this->run->getId();
        $this->recorder()->record(new RecordPelleMovementInput(
            $member->getId(), $amount, PelleKind::Bronze, null, PelleReason::BronzeStart, 'Départ', null,
            sprintf('test-%s:%s:%s', $key, $runId, $member->getId()),
            runId: $runId,
        ));
    }

    private function balance(User $member, ?string $runId = null): int
    {
        return $this->ledger()->balance($member->getId(), PelleKind::Bronze, null, $runId ?? $this->run->getId());
    }

    /**
     * @return list<PelleMovement>
     */
    private function movements(PelleReason $reason): array
    {
        return $this->entityManager->getRepository(PelleMovement::class)->findBy(['reason' => $reason], ['amount' => 'DESC']);
    }

    private function runJobs(): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        self::assertInstanceOf(InMemoryTransport::class, $transport);
        $jobs = [];
        foreach ($transport->getSent() as $envelope) {
            $message = $envelope->getMessage();
            if ($message instanceof ReconcileRunBronzeJob) {
                $jobs[] = $message;
            }
        }
        $transport->reset();
        foreach ($jobs as $job) {
            $this->reconcile($job->runId);
        }
    }

    private function reconcile(?string $runId = null): void
    {
        $reconcile = self::getContainer()->get(ReconcileRunBronze::class);
        self::assertInstanceOf(ReconcileRunBronze::class, $reconcile);
        $reconcile->reconcile($runId ?? $this->run->getId());
    }

    private function recorder(): RecordPelleMovement
    {
        $recorder = self::getContainer()->get(RecordPelleMovement::class);
        self::assertInstanceOf(RecordPelleMovement::class, $recorder);

        return $recorder;
    }

    private function ledger(): PelleMovementRepositoryInterface
    {
        $ledger = self::getContainer()->get(PelleMovementRepositoryInterface::class);
        self::assertInstanceOf(PelleMovementRepositoryInterface::class, $ledger);

        return $ledger;
    }
}
