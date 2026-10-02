<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Service;

/**
 * Which slots just made a check (story 30.45), from two successive players pushes of the bridge: a slot whose
 * `checks_done` grew since the previous push. Archipelago knows the slot, not the person - the activity belongs
 * to everyone attached to the slot.
 *
 * Without a previous push, or for a slot absent from it, there is nothing to compare with and nothing is dated:
 * a late first push must not make every slot look like it just played.
 */
final class SlotCheckActivity
{
    /**
     * @param array<array-key, mixed>|null $previous the previous players push, `{"slots": {"<n>": {...}}}`
     * @param array<array-key, mixed>      $current  the push just received
     *
     * @return list<string> the slot names
     */
    public static function slotsWithNewChecks(?array $previous, array $current): array
    {
        if (null === $previous) {
            return [];
        }
        $before = self::checksBySlotName($previous);
        $names = [];
        foreach (self::checksBySlotName($current) as $slotName => $checks) {
            if (isset($before[$slotName]) && $checks > $before[$slotName]) {
                $names[] = (string) $slotName;
            }
        }

        return $names;
    }

    /**
     * @param array<array-key, mixed> $payload
     *
     * @return array<array-key, int> keyed by slot name - PHP turns a numeric name ("123") into an int key
     */
    private static function checksBySlotName(array $payload): array
    {
        $slots = $payload['slots'] ?? null;
        if (!is_array($slots)) {
            return [];
        }
        $checks = [];
        foreach ($slots as $slot) {
            if (!is_array($slot)) {
                continue;
            }
            $name = $slot['slot_name'] ?? null;
            $done = $slot['checks_done'] ?? null;
            if (is_string($name) && is_int($done)) {
                $checks[$name] = $done;
            }
        }

        return $checks;
    }
}
