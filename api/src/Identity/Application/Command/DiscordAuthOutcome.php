<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

/**
 * Routing outcome of {@see HandleDiscordAuthCallback::handle} - each case drives the sign-in redirect the
 * OAuth auth callback issues. {@see DiscordAuthOutcome::LoggedIn} and {@see DiscordAuthOutcome::Registered}
 * carry the authenticated user id in {@see DiscordAuthResult}; the others authenticate no one.
 */
enum DiscordAuthOutcome: string
{
    case LoggedIn = 'logged_in';
    case Registered = 'registered';
    case EmailConflict = 'email_conflict';
    case NoVerifiedEmail = 'no_verified_email';
    case DiscordError = 'discord_error';
}
