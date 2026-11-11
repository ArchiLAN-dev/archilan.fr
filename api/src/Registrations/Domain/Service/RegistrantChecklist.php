<?php

declare(strict_types=1);

namespace App\Registrations\Domain\Service;

/**
 * Story 53.2: what a registrant still has to do before the event, line by line. Pure: the query gathers the facts,
 * this decides which lines apply and where each one stands. The frontend builds the wording and the link from `key`.
 *
 * - `todo`: a required step not done yet;
 * - `optional`: a nice-to-have not done (or, for surveys, waiting);
 * - `done`: nothing left.
 *
 * @phpstan-type ChecklistItem array{key: string, state: 'done'|'todo'|'optional', required: bool, detail?: array<string, int|bool|string>}
 */
final class RegistrantChecklist
{
    /** HelloAsso and the other states the admin dashboard already reads as paid. */
    private const array PAID_STATUSES = ['processed', 'confirmed', 'paid', 'succeeded'];

    /**
     * @param int          $configurableSlots         slots whose game can be configured (its apworld is ready)
     * @param list<string> $slotsMissingYaml          the configurable slots with neither a YAML of the player nor a default
     * @param bool|null    $registrationQuestionsDone null when the event asks no question at registration
     * @param bool         $paymentExpected           the event sells its tickets through HelloAsso
     * @param string|null  $paymentStatus             the HelloAsso order found for the member's email, if any
     *
     * @return list<ChecklistItem>
     */
    public static function build(
        bool $confirmed,
        bool $gameSelectionEnabled,
        int $slots,
        int $configurableSlots,
        array $slotsMissingYaml,
        ?bool $registrationQuestionsDone,
        int $pendingSurveys,
        bool $paymentExpected,
        ?string $paymentStatus,
        bool $paymentStale,
        bool $hasPresence,
        bool $discordLinked,
    ): array {
        $items = [self::required('confirm', $confirmed)];

        if ($gameSelectionEnabled) {
            $items[] = self::required('games', $slots > 0);
        }

        // Same reading as the registration recap: a slot with the game's default options is ready.
        if ($configurableSlots > 0) {
            $missing = count($slotsMissingYaml);
            $item = [...self::required('yaml', 0 === $missing), 'detail' => ['done' => max(0, $configurableSlots - $missing), 'total' => $configurableSlots]];
            if (0 !== $missing) {
                $item['detail']['firstSlotId'] = $slotsMissingYaml[0];
            }
            $items[] = $item;
        }

        if (null !== $registrationQuestionsDone) {
            $items[] = self::required('registration_questions', $registrationQuestionsDone);
        }

        if ($pendingSurveys > 0) {
            $items[] = ['key' => 'surveys', 'state' => 'optional', 'required' => false, 'detail' => ['count' => $pendingSurveys]];
        }

        if ($paymentExpected) {
            $paid = null !== $paymentStatus && in_array(strtolower(trim($paymentStatus)), self::PAID_STATUSES, true);
            $item = self::required('payment', $paid);
            if (!$paid) {
                // A payment made minutes ago may not be synced yet: the front says so rather than "not paid".
                $item['detail'] = ['found' => null !== $paymentStatus, 'stale' => $paymentStale];
            }
            $items[] = $item;
        }

        $items[] = self::optional('presence', $hasPresence);
        $items[] = self::optional('discord', $discordLinked);

        return $items;
    }

    /**
     * @return array{key: string, state: 'done'|'todo', required: true}
     */
    private static function required(string $key, bool $done): array
    {
        return ['key' => $key, 'state' => $done ? 'done' : 'todo', 'required' => true];
    }

    /**
     * @return array{key: string, state: 'done'|'optional', required: false}
     */
    private static function optional(string $key, bool $done): array
    {
        return ['key' => $key, 'state' => $done ? 'done' : 'optional', 'required' => false];
    }
}
