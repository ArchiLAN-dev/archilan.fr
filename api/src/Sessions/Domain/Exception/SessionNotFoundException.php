<?php

declare(strict_types=1);

namespace App\Sessions\Domain\Exception;

final class SessionNotFoundException extends \DomainException
{
    public function __construct(string $sessionId)
    {
        parent::__construct(sprintf('Session "%s" introuvable.', $sessionId));
    }
}
