<?php

declare(strict_types=1);

namespace App\Community\Application\Command;

use App\Community\Application\Support\AvatarFrameFileRule;
use App\Community\Domain\Entity\AvatarFrameDefinition;
use App\Community\Domain\Enum\AvatarFrameAccess;
use App\Community\Domain\Repository\AvatarFrameDefinitionRepositoryInterface;
use App\Community\Domain\ValueObject\AvatarFrame;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ValidationException;
use App\Shared\Application\Support\PublicMediaUrlResolver;
use App\Shared\Infrastructure\Adapter\MinioStorageInterface;
use Psr\Clock\ClockInterface;

/**
 * The admin side of the video frames (story 41.10): upload a new frame with its four prepared files, change a
 * frame's name, access or order, retire or restore it. The built-in frames of the code (story 30.46) are managed the
 * same way: their first change creates the row that overrides them, their files stay the built-in ones. Story 41.30:
 * an uploaded frame may have a shade (its dark parts, laid in `multiply`), given at upload or later.
 */
final readonly class ManageAvatarFrames
{
    public function __construct(
        private AvatarFrameDefinitionRepositoryInterface $frames,
        private MinioStorageInterface $storage,
        private PublicMediaUrlResolver $publicMedia,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param array<string, string> $files the bytes of each file, by role (webm, mp4, poster, still, and optionally
     *                                     shadeWebm with shadeMp4)
     *
     * @throws ConflictException   when the key is taken
     * @throws ValidationException when the key, the name, the access or a file is invalid
     */
    public function upload(string $key, string $label, string $access, array $files): UploadedAvatarFrame
    {
        if (AvatarFrame::isValid($key) || null !== $this->frames->find($key)) {
            throw new ConflictException('Cette clé est déjà prise.', 'avatar_frame_key_taken');
        }
        $accessValue = self::access($access);

        $errors = [];
        foreach (AvatarFrameFileRule::ROLES as $role) {
            $refusal = AvatarFrameFileRule::refusal($role, $files[$role] ?? '');
            if (null !== $refusal) {
                $errors[$role] = [$refusal];
            }
        }
        $errors += $this->shadeErrors($files, false);
        if ([] !== $errors) {
            throw new ValidationException('Les fichiers du cadre ne conviennent pas.', $errors, 'avatar_frame_files_invalid');
        }
        $withShade = isset($files['shadeWebm']);

        $now = $this->clock->now();
        try {
            // A fresh name per upload, so a cached file is never served for another frame.
            $stamp = bin2hex(random_bytes(4));
            $keys = [];
            foreach (['webm' => 'webm', 'mp4' => 'mp4', 'poster' => 'webp', 'still' => 'webp'] as $role => $extension) {
                $keys[$role] = sprintf('avatar-frames/%s-%s-%s.%s', $key, $role, $stamp, $extension);
            }
            $definition = AvatarFrameDefinition::upload($key, $label, $accessValue, $keys['webm'], $keys['mp4'], $keys['poster'], $keys['still'], $this->nextPosition(), $now);
            if ($withShade) {
                $keys['shadeWebm'] = sprintf('avatar-frames/%s-shade-%s.webm', $key, $stamp);
                $keys['shadeMp4'] = sprintf('avatar-frames/%s-shade-%s.mp4', $key, $stamp);
                $definition->shadeWith($keys['shadeWebm'], $keys['shadeMp4']);
            }
        } catch (\DomainException $e) {
            throw new ValidationException('Clé (2 à 32 caractères : minuscules, chiffres, _) ou nom invalide.', [], $e->getMessage());
        }

        foreach ($keys as $role => $objectKey) {
            $this->storage->upload($this->publicMedia->bucket(), $objectKey, $files[$role]);
        }
        $this->frames->save($definition);

        return new UploadedAvatarFrame($key);
    }

    /**
     * Story 41.30: gives an uploaded frame its shade, or replaces it.
     *
     * @param array<string, string> $files shadeWebm and shadeMp4
     *
     * @throws NotFoundException   when the frame does not exist
     * @throws ConflictException   when the frame is a built-in one (its files are the code's)
     * @throws ValidationException when a file is missing or invalid
     */
    public function shade(string $key, array $files): void
    {
        $definition = $this->uploaded($key);
        $errors = $this->shadeErrors($files, true);
        if ([] !== $errors) {
            throw new ValidationException('Les fichiers de l\'ombre ne conviennent pas.', $errors, 'avatar_frame_files_invalid');
        }
        $stamp = bin2hex(random_bytes(4));
        $webm = sprintf('avatar-frames/%s-shade-%s.webm', $key, $stamp);
        $mp4 = sprintf('avatar-frames/%s-shade-%s.mp4', $key, $stamp);
        $this->storage->upload($this->publicMedia->bucket(), $webm, $files['shadeWebm']);
        $this->storage->upload($this->publicMedia->bucket(), $mp4, $files['shadeMp4']);
        $definition->shadeWith($webm, $mp4);
        $this->frames->save($definition);
    }

    /**
     * Story 41.30: takes an uploaded frame's shade off (its files stay in the bucket, like a retired frame's).
     *
     * @throws NotFoundException when the frame does not exist
     * @throws ConflictException when the frame is a built-in one
     */
    public function removeShade(string $key): void
    {
        $definition = $this->uploaded($key);
        $definition->shadeWith(null, null);
        $this->frames->save($definition);
    }

    /**
     * @throws NotFoundException   when the frame does not exist
     * @throws ValidationException when the name, the access or the order is invalid
     */
    public function update(string $key, ?string $label, ?string $access, ?int $position): void
    {
        $definition = $this->definition($key);
        try {
            $definition->update($label, null === $access ? null : self::access($access), $position);
        } catch (\DomainException $e) {
            throw new ValidationException('Nom invalide (60 caractères au plus).', [], $e->getMessage());
        }
        $this->frames->save($definition);
    }

    /**
     * Retired, a frame leaves the picker and the profiles that wear it, which keep its key for a restore.
     *
     * @throws NotFoundException when the frame does not exist
     */
    public function retire(string $key): void
    {
        $definition = $this->definition($key);
        $definition->retire($this->clock->now());
        $this->frames->save($definition);
    }

    /**
     * @throws NotFoundException when the frame does not exist
     */
    public function restore(string $key): void
    {
        $definition = $this->definition($key);
        $definition->restore();
        $this->frames->save($definition);
    }

    /** The row of a frame, created from the code's defaults for a built-in frame changed for the first time. */
    private function definition(string $key): AvatarFrameDefinition
    {
        $definition = $this->frames->find($key);
        if ($definition instanceof AvatarFrameDefinition) {
            return $definition;
        }
        $position = array_search($key, AvatarFrame::LEGENDARY, true);
        if (false === $position) {
            throw new NotFoundException('Cadre introuvable.', 'avatar_frame_not_found');
        }

        return AvatarFrameDefinition::overrideBuiltIn($key, AvatarFrame::LEGENDARY_LABELS[$key] ?? $key, AvatarFrameAccess::Admins, $position, $this->clock->now());
    }

    private function uploaded(string $key): AvatarFrameDefinition
    {
        $definition = $this->frames->find($key);
        if (!$definition instanceof AvatarFrameDefinition) {
            throw new NotFoundException('Cadre introuvable.', 'avatar_frame_not_found');
        }
        if (!$definition->hasOwnFiles()) {
            throw new ConflictException('Un cadre intégré au site n\'a pas d\'ombre à envoyer.', 'avatar_frame_built_in');
        }

        return $definition;
    }

    /**
     * The shade's files: both or none (none allowed only when `$required` is false).
     *
     * @param array<string, string> $files
     *
     * @return array<string, list<string>>
     */
    private function shadeErrors(array $files, bool $required): array
    {
        $given = array_filter(AvatarFrameFileRule::SHADE_ROLES, static fn (string $role): bool => isset($files[$role]));
        if ([] === $given && !$required) {
            return [];
        }
        $errors = [];
        foreach (AvatarFrameFileRule::SHADE_ROLES as $role) {
            $refusal = isset($files[$role]) ? AvatarFrameFileRule::refusal($role, $files[$role]) : 'L\'ombre demande ses deux vidéos, WebM et MP4.';
            if (null !== $refusal) {
                $errors[$role] = [$refusal];
            }
        }

        return $errors;
    }

    private function nextPosition(): int
    {
        $position = \count(AvatarFrame::LEGENDARY) - 1;
        foreach ($this->frames->all() as $definition) {
            $position = max($position, $definition->getPosition());
        }

        return $position + 1;
    }

    private static function access(string $access): AvatarFrameAccess
    {
        $value = AvatarFrameAccess::tryFrom($access);
        if (null === $value) {
            throw new ValidationException('Accès inconnu.', ['access' => ['free, members, admins ou shop.']], 'avatar_frame_access_invalid');
        }

        return $value;
    }
}
