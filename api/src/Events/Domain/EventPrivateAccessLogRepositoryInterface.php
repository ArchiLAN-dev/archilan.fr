<?php

declare(strict_types=1);

namespace App\Events\Domain;

interface EventPrivateAccessLogRepositoryInterface
{
    public function save(EventPrivateAccessLog $log): void;
}
