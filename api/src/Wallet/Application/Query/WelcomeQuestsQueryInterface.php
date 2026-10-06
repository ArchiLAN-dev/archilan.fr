<?php

declare(strict_types=1);

namespace App\Wallet\Application\Query;

use App\Wallet\Domain\Enum\WelcomeStep;

/**
 * Story 41.25: the first steps of the newcomers, read from what was actually played:
 *
 * - discord: the account linked to Discord;
 * - check: a check of the session feed made by a slot the member plays, or by their weekly attempt;
 * - weekly: a weekly attempt launched with at least a check or its goal;
 * - partner: another member made a check in a session where the member made one;
 * - goal: a goal reached by a slot the member plays, or by their weekly attempt.
 */
interface WelcomeQuestsQueryInterface
{
    /**
     * The members who may still earn a step: created from the instant (every member when null), neither banned nor
     * erased, not paid for every step yet.
     *
     * @return list<string>
     */
    public function candidates(?\DateTimeImmutable $since): array;

    /**
     * Those of the members who did the step and were not paid for it, with their Discord id (the Discord step pays
     * a Discord account once, whichever site account it is linked to).
     *
     * @param list<string> $userIds
     *
     * @return list<array{userId: string, discordId: string|null}>
     */
    public function unpaid(WelcomeStep $step, array $userIds): array;

    /** @return list<WelcomeStep> the steps the member did */
    public function doneBy(string $userId): array;

    /** @return list<WelcomeStep> the steps already paid to the member */
    public function paidTo(string $userId): array;

    /** When the member's account was created, null for an unknown member. */
    public function joinedAt(string $userId): ?\DateTimeImmutable;
}
