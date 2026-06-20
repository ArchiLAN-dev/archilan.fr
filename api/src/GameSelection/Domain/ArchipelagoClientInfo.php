<?php

declare(strict_types=1);

namespace App\GameSelection\Domain;

use Doctrine\ORM\Mapping as ORM;

/**
 * Single global record holding the Archipelago client (launcher) version players must install to
 * match the host, plus its download URL (story 31.8). Version parity is the #1 cause of failed
 * multiworld joins. One row, identified by {@see SINGLETON_ID}.
 */
#[ORM\Entity]
final class ArchipelagoClientInfo
{
    public const SINGLETON_ID = 'default';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'string', length: 32)]
        private string $id,
        #[ORM\Column(type: 'string', length: 50)]
        private string $version,
        #[ORM\Column(name: 'download_url', type: 'string', length: 500)]
        private string $downloadUrl,
        #[ORM\Column(name: 'updated_at', type: 'datetimetz_immutable')]
        private \DateTimeImmutable $updatedAt,
    ) {
    }

    public static function create(string $version, string $downloadUrl, \DateTimeImmutable $now): self
    {
        return new self(self::SINGLETON_ID, trim($version), trim($downloadUrl), $now);
    }

    public function update(string $version, string $downloadUrl, \DateTimeImmutable $now): void
    {
        $this->version = trim($version);
        $this->downloadUrl = trim($downloadUrl);
        $this->updatedAt = $now;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getDownloadUrl(): string
    {
        return $this->downloadUrl;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }
}
