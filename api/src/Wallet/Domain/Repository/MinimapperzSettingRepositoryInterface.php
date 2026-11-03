<?php

declare(strict_types=1);

namespace App\Wallet\Domain\Repository;

use App\Wallet\Domain\Entity\MinimapperzSetting;

interface MinimapperzSettingRepositoryInterface
{
    public function find(string $eventId): ?MinimapperzSetting;

    public function save(MinimapperzSetting $setting): void;

    /**
     * Story 49.3: the links switched on, the ones the collection reads.
     *
     * @return list<MinimapperzSetting>
     */
    public function findEnabled(): array;
}
