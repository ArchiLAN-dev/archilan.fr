<?php

declare(strict_types=1);

namespace App\Tests\Unit\PersonalRuns;

use App\PersonalRuns\Domain\Entity\Run;
use PHPUnit\Framework\TestCase;

/**
 * Story 16.14 : qui a le droit de démarrer une run, dans quel état.
 *
 * La règle est étroite par construction. `start()` couvre deux usages que rien ne distingue côté
 * appelant : le premier lancement depuis `draft`, qui fige la configuration et les slots de tous
 * les participants, et la reprise depuis `idle`, qui rallume ce qui existait déjà. Seul le second
 * est ouvert au participant, et c'est exactement ce que ces tests verrouillent.
 */
final class RunStartAuthorizationTest extends TestCase
{
    public function testOwnerMayStartADraftRun(): void
    {
        $run = $this->draftRun();

        self::assertTrue($run->isStartAllowedFor('owner-1', false));
    }

    public function testOwnerMayRestartAnIdleRun(): void
    {
        $run = $this->idleRun();

        self::assertTrue($run->isStartAllowedFor('owner-1', false));
    }

    public function testParticipantMayRestartAnIdleRun(): void
    {
        $run = $this->idleRun();

        self::assertTrue($run->isStartAllowedFor('participant-1', true));
    }

    public function testParticipantMayNotStartADraftRun(): void
    {
        // Le cas qui justifie que la règle regarde le statut et pas seulement l'appelant : relâcher
        // la garde sans distinguer l'état ouvrirait aussi le premier lancement.
        $run = $this->draftRun();

        self::assertFalse($run->isStartAllowedFor('participant-1', true));
    }

    public function testStrangerMayNotStartAnIdleRun(): void
    {
        $run = $this->idleRun();

        self::assertFalse($run->isStartAllowedFor('stranger-1', false));
    }

    public function testStrangerMayNotStartADraftRun(): void
    {
        $run = $this->draftRun();

        self::assertFalse($run->isStartAllowedFor('stranger-1', false));
    }

    public function testParticipantMayNotStartATerminalRun(): void
    {
        $run = $this->idleRun();
        $run->cancel(new \DateTimeImmutable('2026-08-15 12:00:00'));

        self::assertFalse($run->isStartAllowedFor('participant-1', true));
    }

    private function draftRun(): Run
    {
        return Run::create('owner-1', 'Ma run', new \DateTimeImmutable('2026-08-15 10:00:00'));
    }

    private function idleRun(): Run
    {
        $run = $this->draftRun();
        $run->markStopped(new \DateTimeImmutable('2026-08-15 11:00:00'));

        return $run;
    }
}
