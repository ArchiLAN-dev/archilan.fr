<?php

declare(strict_types=1);

namespace App\SessionConfig\Domain;

interface SessionConfigProfileRepositoryInterface
{
    /**
     * Returns the stored profile for a type, or the domain default when none is stored yet.
     */
    public function get(SessionType $type): SessionConfig;

    public function save(SessionType $type, SessionConfig $config): void;
}
