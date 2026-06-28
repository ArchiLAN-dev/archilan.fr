<?php

declare(strict_types=1);

namespace App\Identity\Application;

use App\Identity\Domain\User;
use App\Identity\Domain\UserRepositoryInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;

/**
 * Self-service profile-URL (slug) change (story 2.10).
 *
 * Rules:
 *  - moving to a NEW slug is allowed once per {@see COOLDOWN_DAYS} days;
 *  - reclaiming your own just-released slug (`previousSlug`) is the "undo" and bypasses the cooldown;
 *  - a slug released by ANOTHER user stays reserved for that window (only its former owner can take it back).
 *
 * Because only one previous slug is kept per user and the cooldown caps changes to ~1/month, a single
 * user can reserve at most one slug at a time - no hoarding.
 */
final readonly class ChangeUserSlug
{
    public const COOLDOWN_DAYS = 30;
    public const MIN_LENGTH = 3;
    public const MAX_LENGTH = 30;

    /** Reserved for routing/UX (the /joueurs/{slug} namespace and common words). */
    private const RESERVED = [
        'me', 'moi', 'admin', 'administrateur', 'compte', 'account', 'settings', 'parametres',
        'nouveau', 'new', 'joueurs', 'joueur', 'user', 'users', 'succes', 'null', 'undefined', 'api',
    ];

    public function __construct(
        private UserRepositoryInterface $userRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{outcome: 'ok'|'error', error?: string, slug?: string, nextAllowedAt?: string}
     */
    public function change(string $userId, string $requested): array
    {
        $user = $this->userRepository->findById($userId);
        if (!$user instanceof User) {
            return ['outcome' => 'error', 'error' => 'not_found'];
        }

        $slug = self::sanitize($requested);
        if (null === $slug) {
            return ['outcome' => 'error', 'error' => 'slug_invalid'];
        }
        if (in_array($slug, self::RESERVED, true)) {
            return ['outcome' => 'error', 'error' => 'slug_reserved_word'];
        }
        if ($slug === $user->getSlug()) {
            return ['outcome' => 'error', 'error' => 'slug_unchanged'];
        }

        $now = new \DateTimeImmutable();
        $cutoff = $now->sub(new \DateInterval(sprintf('P%dD', self::COOLDOWN_DAYS)));
        $isReclaim = null !== $user->getPreviousSlug() && $slug === $user->getPreviousSlug();

        // Cooldown applies only when moving to a NEW slug; reclaiming your own previous slug is exempt.
        if (!$isReclaim) {
            $changedAt = $user->getSlugChangedAt();
            if (null !== $changedAt && $changedAt > $cutoff) {
                return [
                    'outcome' => 'error',
                    'error' => 'slug_cooldown',
                    'nextAllowedAt' => $changedAt->add(new \DateInterval(sprintf('P%dD', self::COOLDOWN_DAYS)))->format(\DateTimeInterface::ATOM),
                ];
            }
        }

        if ($this->userRepository->existsBySlug($slug)) {
            return ['outcome' => 'error', 'error' => 'slug_taken'];
        }
        // Reserved by another user who released it within the window (former owner excluded → reclaim ok).
        if ($this->userRepository->isSlugReserved($slug, $cutoff, $userId)) {
            return ['outcome' => 'error', 'error' => 'slug_reserved'];
        }

        $user->changeSlug($slug, $now);

        try {
            $this->userRepository->flush();
        } catch (UniqueConstraintViolationException) {
            return ['outcome' => 'error', 'error' => 'slug_taken'];
        }

        $this->logger->info('user.slug_changed', ['userId' => $userId, 'slug' => $slug]);

        return ['outcome' => 'ok', 'slug' => $slug];
    }

    /**
     * Lowercases/trims and validates the format. Returns the clean slug, or null when invalid.
     * Rejects anything the canonical slugifier would alter (spaces, accents, punctuation, leading/trailing
     * or doubled hyphens) and enforces the length bounds.
     */
    public static function sanitize(string $requested): ?string
    {
        $slug = mb_strtolower(trim($requested));

        if (mb_strlen($slug) < self::MIN_LENGTH || mb_strlen($slug) > self::MAX_LENGTH) {
            return null;
        }
        if (1 !== preg_match('/^[a-z0-9](?:[a-z0-9-]*[a-z0-9])$/', $slug)) {
            return null;
        }
        if ($slug !== SlugGenerator::normalize($slug)) {
            return null;
        }

        return $slug;
    }
}
