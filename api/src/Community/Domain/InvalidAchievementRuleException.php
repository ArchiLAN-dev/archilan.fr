<?php

declare(strict_types=1);

namespace App\Community\Domain;

/**
 * Raised when an achievement rule tree is malformed (story 30.16).
 */
final class InvalidAchievementRuleException extends \RuntimeException
{
}
