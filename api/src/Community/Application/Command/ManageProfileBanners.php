<?php

declare(strict_types=1);

namespace App\Community\Application\Command;

use App\Community\Application\Support\ProfileBannerFileRule;
use App\Community\Domain\Entity\ProfileBannerDefinition;
use App\Community\Domain\Enum\AvatarFrameAccess;
use App\Community\Domain\Repository\ProfileBannerDefinitionRepositoryInterface;
use App\Community\Domain\ValueObject\BannerPreset;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use App\Shared\Application\Support\PublicMediaUrlResolver;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use Psr\Clock\ClockInterface;

/**
 * The admin side of the profile banners (story 41.11): upload a banner (a still image, and a looped video for an
 * animated one), change a banner's name, access or order, retire or restore it. The presets of the code are managed
 * the same way: their first change creates the row that overrides them. The default preset stays open to everyone:
 * it is what a profile falls back on.
 */
final readonly class ManageProfileBanners
{
    public function __construct(
        private ProfileBannerDefinitionRepositoryInterface $banners,
        private MinioStorageInterface $storage,
        private PublicMediaUrlResolver $publicMedia,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, string> $files the bytes of each file, by role (image, webm, mp4)
     *
     * @throws ConflictException   when the key is taken
     * @throws ValidationException when the key, the name, the access or a file is invalid
     */
    public function upload(string $key, string $label, string $access, array $files): UploadedProfileBanner
    {
        if (BannerPreset::isValid($key) || null !== $this->banners->find($key)) {
            throw new ConflictException('Cette clé est déjà prise.', 'profile_banner_key_taken');
        }
        $accessValue = self::access($access);

        $image = $files['image'] ?? '';
        $webm = $files['webm'] ?? '';
        $mp4 = $files['mp4'] ?? '';
        $animated = '' !== $webm || '' !== $mp4;
        $errors = [];
        $imageRefusal = ProfileBannerFileRule::imageRefusal($image);
        if (null !== $imageRefusal) {
            $errors['image'] = [$imageRefusal];
        }
        if ($animated) {
            foreach (['webm' => $webm, 'mp4' => $mp4] as $role => $bytes) {
                $refusal = '' === $bytes ? 'Une bannière animée a besoin des deux vidéos, WebM et MP4.' : ProfileBannerFileRule::videoRefusal($role, $bytes);
                if (null !== $refusal) {
                    $errors[$role] = [$refusal];
                }
            }
        }
        if ([] !== $errors) {
            throw new ValidationException('Les fichiers de la bannière ne conviennent pas.', $errors, 'profile_banner_files_invalid');
        }

        // A fresh name per upload, so a cached file is never served for another banner.
        $stamp = bin2hex(random_bytes(4));
        $objects = [sprintf('profile-banners/%s-image-%s.%s', $key, $stamp, ProfileBannerFileRule::imageExtension($image) ?? 'webp') => $image];
        $video = null;
        if ($animated) {
            $video = ['webm' => sprintf('profile-banners/%s-webm-%s.webm', $key, $stamp), 'mp4' => sprintf('profile-banners/%s-mp4-%s.mp4', $key, $stamp)];
            $objects[$video['webm']] = $webm;
            $objects[$video['mp4']] = $mp4;
        }
        try {
            $definition = ProfileBannerDefinition::upload($key, $label, $accessValue, array_key_first($objects), $video, $this->nextPosition(), $this->clock->now());
        } catch (\DomainException $e) {
            throw new ValidationException('Clé (2 à 32 caractères : minuscules, chiffres, _) ou nom invalide.', [], $e->getMessage());
        }

        foreach ($objects as $objectKey => $bytes) {
            $this->storage->upload($this->publicMedia->bucket(), $objectKey, $bytes);
        }
        $this->banners->save($definition);

        return new UploadedProfileBanner($key);
    }

    /**
     * @throws NotFoundException   when the banner does not exist
     * @throws ValidationException when the name, the access or the order is invalid
     */
    public function update(string $key, ?string $label, ?string $access, ?int $position): void
    {
        $accessValue = null === $access ? null : self::access($access);
        if (BannerPreset::DEFAULT === $key && null !== $accessValue && AvatarFrameAccess::Free !== $accessValue) {
            throw new ValidationException('La bannière par défaut reste ouverte à tous.', [], 'profile_banner_default_locked');
        }
        $definition = $this->definition($key);
        try {
            $definition->update($label, $accessValue, $position);
        } catch (\DomainException $e) {
            throw new ValidationException('Nom invalide (60 caractères au plus).', [], $e->getMessage());
        }
        $this->banners->save($definition);
    }

    /**
     * Retired, a banner leaves the editor and the profiles that show it fall back on the default one; they keep its
     * key for a restore.
     *
     * @throws NotFoundException   when the banner does not exist
     * @throws ValidationException for the default banner
     */
    public function retire(string $key): void
    {
        if (BannerPreset::DEFAULT === $key) {
            throw new ValidationException('La bannière par défaut ne peut pas être retirée.', [], 'profile_banner_default_locked');
        }
        $definition = $this->definition($key);
        $definition->retire($this->clock->now());
        $this->banners->save($definition);
    }

    /**
     * @throws NotFoundException when the banner does not exist
     */
    public function restore(string $key): void
    {
        $definition = $this->definition($key);
        $definition->restore();
        $this->banners->save($definition);
    }

    /** The row of a banner, created from the code's defaults for a preset changed for the first time. */
    private function definition(string $key): ProfileBannerDefinition
    {
        $definition = $this->banners->find($key);
        if ($definition instanceof ProfileBannerDefinition) {
            return $definition;
        }
        $position = array_search($key, BannerPreset::ALL, true);
        if (false === $position) {
            throw new NotFoundException('Bannière introuvable.', 'profile_banner_not_found');
        }
        $access = BannerPreset::allowedFor($key) ? AvatarFrameAccess::Free : AvatarFrameAccess::Shop;

        return ProfileBannerDefinition::overrideBuiltIn($key, BannerPreset::LABELS[$key] ?? $key, $access, $position, $this->clock->now());
    }

    private function nextPosition(): int
    {
        $position = \count(BannerPreset::ALL) - 1;
        foreach ($this->banners->all() as $definition) {
            $position = max($position, $definition->getPosition());
        }

        return $position + 1;
    }

    private static function access(string $access): AvatarFrameAccess
    {
        $value = AvatarFrameAccess::tryFrom($access);
        if (null === $value) {
            throw new ValidationException('Accès inconnu.', ['access' => ['free, members, admins ou shop.']], 'profile_banner_access_invalid');
        }

        return $value;
    }
}
