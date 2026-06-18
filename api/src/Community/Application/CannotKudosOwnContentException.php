<?php

declare(strict_types=1);

namespace App\Community\Application;

/**
 * Raised when an actor tries to give kudos to their own run or achievement (story 30.11).
 */
final class CannotKudosOwnContentException extends \RuntimeException
{
}
