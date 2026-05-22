<?php

declare(strict_types=1);

namespace App\Registrations\Domain;

interface RegistrationAdminMessageRepositoryInterface
{
    public function save(RegistrationAdminMessage $message): void;
}
