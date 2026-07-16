<?php

declare(strict_types=1);

namespace App\GameSelection\Application\Command;

use App\GameSelection\Domain\Entity\ArchipelagoClientInfo;
use App\GameSelection\Domain\Repository\ArchipelagoClientInfoRepositoryInterface;
use App\Identity\Application\Support\ValidationErrors;
use App\Shared\Application\Exception\ValidationException;
use Psr\Clock\ClockInterface;

final readonly class UpdateArchipelagoClient
{
    public const int MAX_VERSION = 50;
    public const int MAX_URL = 500;

    public function __construct(
        private ArchipelagoClientInfoRepositoryInterface $repository,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ValidationException when the version or download URL is invalid
     */
    public function update(string $version, string $downloadUrl): void
    {
        $version = trim($version);
        $downloadUrl = trim($downloadUrl);

        $errors = new ValidationErrors();
        if ('' === $version) {
            $errors->add('version', 'La version est requise.');
        } elseif (mb_strlen($version) > self::MAX_VERSION) {
            $errors->add('version', sprintf('La version ne doit pas dépasser %d caractères.', self::MAX_VERSION));
        }
        if ('' === $downloadUrl || !self::isHttpUrl($downloadUrl)) {
            $errors->add('downloadUrl', 'Une URL de téléchargement http(s) est requise.');
        } elseif (mb_strlen($downloadUrl) > self::MAX_URL) {
            $errors->add('downloadUrl', sprintf('L\'URL ne doit pas dépasser %d caractères.', self::MAX_URL));
        }

        if ([] !== $errors->toArray()) {
            throw new ValidationException('Le client Archipelago contient des erreurs.', $errors->toArray());
        }

        $now = $this->clock->now();
        $info = $this->repository->get();
        if (null === $info) {
            $info = ArchipelagoClientInfo::create($version, $downloadUrl, $now);
        } else {
            $info->update($version, $downloadUrl, $now);
        }
        $this->repository->save($info);
    }

    private static function isHttpUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }
}
