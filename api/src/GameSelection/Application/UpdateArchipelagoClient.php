<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

use App\GameSelection\Domain\ArchipelagoClientInfo;
use App\GameSelection\Domain\ArchipelagoClientInfoRepositoryInterface;
use App\Identity\Application\ValidationErrors;

final readonly class UpdateArchipelagoClient
{
    public const MAX_VERSION = 50;
    public const MAX_URL = 500;

    public function __construct(
        private ArchipelagoClientInfoRepositoryInterface $repository,
    ) {
    }

    /**
     * @return array{errors: array<string, list<string>>}
     */
    public function update(string $version, string $downloadUrl): array
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
            return ['errors' => $errors->toArray()];
        }

        $now = new \DateTimeImmutable();
        $info = $this->repository->get();
        if (null === $info) {
            $info = ArchipelagoClientInfo::create($version, $downloadUrl, $now);
        } else {
            $info->update($version, $downloadUrl, $now);
        }
        $this->repository->save($info);

        return ['errors' => []];
    }

    private static function isHttpUrl(string $url): bool
    {
        $scheme = parse_url($url, PHP_URL_SCHEME);

        return is_string($scheme) && in_array(strtolower($scheme), ['http', 'https'], true);
    }
}
