<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

/**
 * Which slot a generated output file belongs to: the one rule shared by the private-run and the
 * event patch downloads (story 17.32), so a player never gets another player's patch.
 */
final class SlotPatchFile
{
    /**
     * Whether an output filename is a patch belonging to one of the caller's slot names.
     *
     * AP patch files are named "AP_{seed}_P{slotNumber}_{slotName}.{ext}". Some apworlds append a
     * game/version suffix *after* the slot name (e.g. "masterkafey_SHA" → file
     * "AP_..._P2_masterkafey_SHA_SHAR_0.6.7.apshar"), so the slot name is matched as a prefix at an
     * underscore boundary, not only by exact equality - otherwise those patches are wrongly filtered
     * out.
     *
     * Custom slot names (story 9.37) break the old "one underscore per name" invariant: a name like
     * "master" can be a `_`-boundary prefix of another player's "master_kafey". So the file is
     * attributed to the **single longest** matching name among ALL session slots (`$allSlotNames`),
     * and access is granted only when that winner is one of the caller's (`$ownSlotNames`) - a player
     * can never grab another player's patch even if their name is a prefix of it. The shared multidata
     * (.archipelago) and any *_spoiler* file are never patches.
     *
     * @param list<string>      $ownSlotNames the caller's slots
     * @param list<string>|null $allSlotNames every slot in the session; defaults to $ownSlotNames
     */
    public static function belongsTo(string $filename, array $ownSlotNames, ?array $allSlotNames = null): bool
    {
        $allSlotNames ??= $ownSlotNames;

        if ('archipelago' === strtolower(pathinfo($filename, \PATHINFO_EXTENSION))) {
            return false;
        }
        if (str_contains(strtolower($filename), '_spoiler')) {
            return false;
        }

        $stem = pathinfo($filename, \PATHINFO_FILENAME);
        $captured = 1 === preg_match('/_P\d+_(.+)$/', $stem, $matches) ? $matches[1] : $stem;

        $winner = null;
        foreach ($allSlotNames as $slotName) {
            if ($captured === $slotName || str_starts_with($captured, $slotName.'_')) {
                if (null === $winner || mb_strlen($slotName) > mb_strlen($winner)) {
                    $winner = $slotName;
                }
            }
        }

        return null !== $winner && in_array($winner, $ownSlotNames, true);
    }
}
