<?php

declare(strict_types=1);

namespace App\Identity\Application\Command;

use App\Identity\Application\Support\SlugGenerator;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Exception\ValidationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Clock\ClockInterface;
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
    public const int COOLDOWN_DAYS = 30;
    public const int MIN_LENGTH = 3;
    public const int MAX_LENGTH = 30;

    /** Reserved for routing/UX (the /joueurs/{slug} namespace and common words). */
    private const array RESERVED = [
        'me', 'moi', 'admin', 'administrateur', 'compte', 'account', 'settings', 'parametres',
        'nouveau', 'new', 'joueurs', 'joueur', 'user', 'users', 'succes', 'null', 'undefined', 'api',
    ];

    public function __construct(
        private UserRepositoryInterface $userRepository,
        private LoggerInterface $logger,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws ValidationException when the slug is unavailable, reserved, unchanged, cooling down, or invalid
     */
    public function change(string $userId, string $requested): SlugChangeResult
    {
        $user = $this->userRepository->findById($userId);
        if (!$user instanceof User) {
            $this->failSlug('not_found');
        }

        $slug = self::sanitize($requested);
        if (null === $slug) {
            $this->failSlug('slug_invalid');
        }
        if (in_array($slug, self::RESERVED, true)) {
            $this->failSlug('slug_reserved_word');
        }
        if ($slug === $user->getSlug()) {
            $this->failSlug('slug_unchanged');
        }

        $now = $this->clock->now();
        $cutoff = $now->sub(new \DateInterval(sprintf('P%dD', self::COOLDOWN_DAYS)));
        $isReclaim = null !== $user->getPreviousSlug() && $slug === $user->getPreviousSlug();

        // Cooldown applies only when moving to a NEW slug; reclaiming your own previous slug is exempt.
        if (!$isReclaim) {
            $changedAt = $user->getSlugChangedAt();
            if (null !== $changedAt && $changedAt > $cutoff) {
                $nextAllowedAt = $changedAt->add(new \DateInterval(sprintf('P%dD', self::COOLDOWN_DAYS)))->format(\DateTimeInterface::ATOM);
                $this->failSlug('slug_cooldown', ['nextAllowedAt' => [$nextAllowedAt]]);
            }
        }

        if ($this->userRepository->existsBySlug($slug)) {
            $this->failSlug('slug_taken');
        }
        // Reserved by another user who released it within the window (former owner excluded → reclaim ok).
        if ($this->userRepository->isSlugReserved($slug, $cutoff, $userId)) {
            $this->failSlug('slug_reserved');
        }

        $user->changeSlug($slug, $now);

        try {
            $this->userRepository->flush();
        } catch (UniqueConstraintViolationException) {
            $this->failSlug('slug_taken');
        }

        $this->logger->info('user.slug_changed', ['userId' => $userId, 'slug' => $slug]);

        return new SlugChangeResult($slug);
    }

    /**
     * @param array<string, mixed> $details
     *
     * @throws ValidationException always (maps the slug error code to its 422 message)
     */
    private function failSlug(string $code, array $details = []): never
    {
        $message = match ($code) {
            'slug_taken' => 'Cette URL est déjà utilisée.',
            'slug_reserved' => 'Cette URL a été libérée récemment et reste réservée 30 jours.',
            'slug_reserved_word' => 'Cette URL est réservée.',
            'slug_cooldown' => 'Tu as déjà changé d\'URL récemment (1 changement tous les 30 jours).',
            'slug_unchanged' => 'C\'est déjà ton URL actuelle.',
            default => 'URL invalide : 3 à 30 caractères, minuscules, chiffres et tirets.',
        };

        throw new ValidationException($message, $details, $code);
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
