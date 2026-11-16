<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Trackers;

/** Story 54.1: builds pack zips for the tests, in memory, with any entry names. */
final class TrackerPackZip
{
    /** A manifest shaped like the Majora's Mask pack, BOM included. */
    public const string MANIFEST = "\xEF\xBB\xBF".<<<'JSON'
        {
            "name": "Majora's Mask: Recompiled",
            "game_name": "The Legend of Zelda - Majoras Mask",
            "package_version": "0.9.5.3",
            "package_uid": "MM_AP",
            "author": "Seto10987, G4M3R L1F3",
            "min_poptracker_version": "0.32.0",
            "target_poptracker_version": "0.34.0",
            "variants": {
                "standard": { "display_name": "Maps and items", "flags": ["ap"] },
                "itemsonly": { "display_name": "Items only", "flags": ["ap"] }, // comment
            },
            "versions_url": "https://raw.githubusercontent.com/o/r/refs/heads/cube/versions.json"
        }
        JSON;

    /** @param array<string, string> $entries entry name => content */
    public static function build(array $entries): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'tpz_');
        if (false === $tmp) {
            throw new \RuntimeException('tempnam failed');
        }
        try {
            $zip = new \ZipArchive();
            if (true !== $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE)) {
                throw new \RuntimeException('zip open failed');
            }
            foreach ($entries as $name => $content) {
                $zip->addFromString($name, $content);
            }
            $zip->close();
            $bytes = file_get_contents($tmp);
            if (false === $bytes) {
                throw new \RuntimeException('zip read failed');
            }

            return $bytes;
        } finally {
            @unlink($tmp);
        }
    }

    /** A pack as a tag's source archive gives it: everything under one folder. */
    public static function sourceArchive(string $root = 'Majoras-Mask-AP-PopTracker-Pack-0.9.5.3', string $manifest = self::MANIFEST): string
    {
        return self::build([
            $root.'/manifest.json' => $manifest,
            $root.'/scripts/init.lua' => 'print("hello")',
            $root.'/items/items.jsonc' => '[]',
        ]);
    }
}
