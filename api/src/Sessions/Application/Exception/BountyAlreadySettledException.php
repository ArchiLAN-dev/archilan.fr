<?php

declare(strict_types=1);

namespace App\Sessions\Application\Exception;

/**
 * Story 41.39: another settlement closed the bounty while this one was writing its movement. Thrown inside the
 * movement's transaction, so the movement rolls back.
 */
final class BountyAlreadySettledException extends \RuntimeException
{
    public function __construct(string $bountyId)
    {
        parent::__construct(sprintf('Bounty %s is already settled.', $bountyId));
    }
}
