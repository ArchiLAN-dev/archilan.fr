<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Repository;

use App\Wallet\Domain\Entity\BlindTestSetting;

interface BlindTestSettingRepositoryInterface
{
    public function find(string $eventId): ?BlindTestSetting;

    public function save(BlindTestSetting $setting): void;
}
