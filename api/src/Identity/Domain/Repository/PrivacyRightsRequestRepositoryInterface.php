<?php

declare(strict_types=1);

namespace App\Identity\Domain\Repository;

use App\Identity\Domain\Entity\PrivacyRightsRequest;

interface PrivacyRightsRequestRepositoryInterface
{
    public function save(PrivacyRightsRequest $request): void;
}
