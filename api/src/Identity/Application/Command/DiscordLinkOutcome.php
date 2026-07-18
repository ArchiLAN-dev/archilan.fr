<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

/**
 * Routing outcome of {@see LinkDiscordToAccount::link} - each case drives the account-security redirect
 * the OAuth link callback issues. A pure discriminant with no payload, so the command returns it directly.
 */
enum DiscordLinkOutcome: string
{
    case Linked = 'linked';
    case DiscordAlreadyUsed = 'discord_already_used';
    case NoVerifiedEmail = 'no_verified_email';
    case DiscordError = 'discord_error';
}
