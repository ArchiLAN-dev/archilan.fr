<?php

declare(strict_types=1);

namespace App\Shared\Application\Support;

/**
 * What a player may read of a slot's reachability (stories 27.12, 27.13): never the item an unchecked or checked
 * location holds, and the spheres only when the run's setting shows them. The REST reads of sessions and weekly
 * runs and the live push all go through here, so the three cannot drift apart again.
 */
final readonly class ReachabilityForPlayer
{
    private const array LOCATION_LISTS = ['reachable_unchecked', 'reachable_checked', 'unreachable_unchecked', 'checked_unreachable'];

    /**
     * @param array<mixed> $data
     *
     * @return array<mixed>
     */
    public function forPlayer(array $data, bool $spheresVisible): array
    {
        if (!$spheresVisible) {
            unset($data['spheres']);
        }

        foreach (self::LOCATION_LISTS as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                $data[$key] = $this->stripItems($data[$key]);
            }
        }

        if (isset($data['spheres']) && is_array($data['spheres'])) {
            $data['spheres'] = array_map(function (mixed $sphere): mixed {
                if (is_array($sphere) && isset($sphere['locations']) && is_array($sphere['locations'])) {
                    $sphere['locations'] = $this->stripItems($sphere['locations']);
                }

                return $sphere;
            }, $data['spheres']);
        }

        return $data;
    }

    /**
     * The Mercure topic a reader of a slot's live reachability subscribes to: the raw result for an admin, the
     * player's view with or without the spheres otherwise. The push publishes all three.
     */
    public function topic(string $sessionId, int $slotIndex, bool $admin, bool $spheresVisible): string
    {
        $base = sprintf('runs/%s/slots/%d/reachable', $sessionId, $slotIndex);

        return match (true) {
            $admin => $base.'/full',
            $spheresVisible => $base.'/spheres',
            default => $base,
        };
    }

    /**
     * @param array<mixed> $locations
     *
     * @return array<mixed>
     */
    private function stripItems(array $locations): array
    {
        return array_map(static function (mixed $entry): mixed {
            if (is_array($entry)) {
                unset($entry['item']);
            }

            return $entry;
        }, $locations);
    }
}
