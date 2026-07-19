<?php

declare(strict_types=1);

namespace App\Registrations\Domain\Repository;

use App\Registrations\Domain\Entity\RegistrationAdminMessage;

interface RegistrationAdminMessageRepositoryInterface
{
    public function save(RegistrationAdminMessage $message): void;
}
