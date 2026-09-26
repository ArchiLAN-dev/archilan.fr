<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

/**
 * Whether a generation failure accuses the game's apworld (story 38.4).
 */
enum DefaultYamlFailureVerdict: string
{
    /** Default YAML on the served apworld: the apworld is at fault. */
    case ApworldAccused = 'apworld_accused';
    /** The player changed their YAML: a config problem, nothing for an admin to do. */
    case CustomYaml = 'custom_yaml';
    /** The apworld has been replaced since, or the game has none. */
    case NotServedHash = 'not_served_hash';
    /** The player's YAML cannot be read, so it cannot be compared. */
    case UnreadableYaml = 'unreadable_yaml';
    /** The game no longer exists. */
    case UnknownGame = 'unknown_game';
}
