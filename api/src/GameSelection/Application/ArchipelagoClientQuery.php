<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

use App\GameSelection\Domain\ArchipelagoClientInfoRepositoryInterface;

final readonly class ArchipelagoClientQuery
{
    public function __construct(
        private ArchipelagoClientInfoRepositoryInterface $repository,
    ) {
    }

    /**
     * @return array{version: string, downloadUrl: string}|null
     */
    public function get(): ?array
    {
        $info = $this->repository->get();
        if (null === $info) {
            return null;
        }

        return ['version' => $info->getVersion(), 'downloadUrl' => $info->getDownloadUrl()];
    }
}
