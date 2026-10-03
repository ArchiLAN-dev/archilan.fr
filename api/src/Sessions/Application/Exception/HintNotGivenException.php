<?php

declare(strict_types=1);

namespace App\Sessions\Application\Exception;

/**
 * The bridge did not give the hint a player paid for (story 41.3): it already existed, the item or location is
 * already found, or no hint showed up after the request. The caller refunds the pelles.
 */
final class HintNotGivenException extends \RuntimeException
{
    public const string ALREADY_HINTED = 'already_hinted';
    public const string ALREADY_FOUND = 'already_found';
    public const string NO_HINT_CREATED = 'no_hint_created';

    public function __construct(public readonly string $reason)
    {
        parent::__construct($reason);
    }
}
