<?php

declare(strict_types=1);

namespace App\Trackers\Infrastructure\Dbal;

/** Reads the `variants` JSON column of a pack version back into typed rows (stories 54.1, 54.2). */
final readonly class TrackerPackVariantRows
{
    /** @return list<array{uid: string, displayName: string, flags: list<string>}> */
    public static function decode(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            return [];
        }
        $variants = [];
        foreach ($decoded as $variant) {
            if (!is_array($variant) || !is_string($variant['uid'] ?? null) || !is_string($variant['displayName'] ?? null)) {
                continue;
            }
            $flags = is_array($variant['flags'] ?? null) ? array_values(array_filter($variant['flags'], is_string(...))) : [];
            $variants[] = ['uid' => $variant['uid'], 'displayName' => $variant['displayName'], 'flags' => $flags];
        }

        return $variants;
    }
}
