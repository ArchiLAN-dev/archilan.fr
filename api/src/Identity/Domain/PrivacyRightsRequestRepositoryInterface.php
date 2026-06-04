<?php

declare(strict_types=1);

namespace App\Identity\Domain;

interface PrivacyRightsRequestRepositoryInterface
{
    public function save(PrivacyRightsRequest $request): void;
}
