<?php

declare(strict_types=1);

namespace App\CatalogSync\Application\Exception;

use App\CatalogSync\Application\Support\ApworldVersionInfo;

/**
 * GitHub's quota is nearly spent. `completedCheck` is the check that brought it down, already recorded
 * on its game (story 38.5 review): a caller reporting the pass must not leave it out.
 */
final class GithubRateLimitException extends \RuntimeException
{
    public function __construct(string $message, public readonly ?ApworldVersionInfo $completedCheck = null)
    {
        parent::__construct($message);
    }
}
