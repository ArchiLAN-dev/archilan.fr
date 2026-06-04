<?php

declare(strict_types=1);

namespace App\Sessions\Domain;

final class SessionNotRunningException extends \DomainException
{
    public function __construct(string $sessionId)
    {
        parent::__construct(sprintf('La session "%s" n\'est pas en cours d\'exécution.', $sessionId));
    }
}
