<?php

declare(strict_types=1);

namespace App\Community\Application\Command;

use App\Community\Application\Port\CosmeticOwnershipInterface;
use App\Community\Domain\Entity\CommunityProfile;
use App\Community\Domain\Repository\CommunityProfileRepositoryInterface;
use App\Community\Domain\ValueObject\Audience;
use App\Community\Domain\ValueObject\AvatarFrame;
use App\Community\Domain\ValueObject\BannerOverlay;
use App\Community\Domain\ValueObject\BannerPreset;
use App\Community\Domain\ValueObject\ImageFraming;
use App\Community\Domain\ValueObject\ShowcaseWidget;
use App\GameSelection\Domain\Repository\GameRepositoryInterface;
use App\Identity\Application\Support\ValidationErrors;
use App\Shared\Application\Exception\ValidationException;
use Psr\Clock\ClockInterface;

/**
 * Owner edit of their own community profile customization (story 30.3). Upserts the profile row and
 * applies validated customization. Throws a typed ValidationException on bad input (epic 35).
 */
final readonly class UpdateCommunityProfile
{
    private const int MAX_SOCIAL_LINKS = 5;
    private const int MAX_FAVORITE_GAMES = 6;

    public function __construct(
        private CommunityProfileRepositoryInterface $profiles,
        private GameRepositoryInterface $games,
        private ClockInterface $clock,
        private CosmeticOwnershipInterface $cosmetics,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @param bool                 $isAdmin story 30.46: the legendary avatar frames are for admins only
     *
     * @throws ValidationException when the customization payload is invalid
     */
    public function update(string $userId, array $input, bool $isAdmin): void
    {
        $errors = new ValidationErrors();

        $displayName = $this->nullableString($input['displayName'] ?? null, 80, 'displayName', $errors);
        $bio = $this->nullableString($input['bio'] ?? null, 2000, 'bio', $errors);
        $tagline = $this->nullableString($input['tagline'] ?? null, 120, 'tagline', $errors);
        $pronouns = $this->nullableString($input['pronouns'] ?? null, 40, 'pronouns', $errors);

        $bannerPreset = is_string($input['bannerPreset'] ?? null) ? $input['bannerPreset'] : BannerPreset::DEFAULT;
        if (!BannerPreset::isValid($bannerPreset)) {
            $errors->add('bannerPreset', 'Bannière invalide.');
        } elseif (!BannerPreset::allowedFor($bannerPreset, $this->cosmetics->ownedKeys($userId, CosmeticOwnershipInterface::BANNER))) {
            // Story 41.7: a shop banner is for who bought it.
            $errors->add('bannerPreset', 'Bannière à acheter en boutique.');
        }

        // Story 30.41: an omitted intensity keeps what the profile holds.
        $bannerOverlay = $input['bannerOverlay'] ?? null;
        if (null !== $bannerOverlay && !BannerOverlay::isValid($bannerOverlay)) {
            $errors->add('bannerOverlay', 'Intensité invalide.');
        }

        // Story 30.43: an omitted framing keeps what the profile holds.
        $avatarFraming = $this->framing($input, 'avatarFraming', $errors);
        $bannerFraming = $this->framing($input, 'bannerFraming', $errors);

        // Story 30.44: an omitted switch keeps what the profile holds.
        $titledName = $input['titledName'] ?? null;
        if (null !== $titledName && !is_bool($titledName)) {
            $errors->add('titledName', 'Valeur invalide.');
        }

        // Left null when the client omits it, and resolved from the stored profile below rather than
        // from the default. This endpoint is a full replace, so falling back to the default here meant a
        // payload without `audience` silently rewrote the setting. That was harmless while the default
        // was the most restrictive value; now that it is `public`, the same line would publish the
        // profile of someone who had chosen Friends only, with no action on their part.
        $audienceInput = is_string($input['audience'] ?? null) ? $input['audience'] : null;
        if (null !== $audienceInput && !Audience::isValid($audienceInput)) {
            $errors->add('audience', 'Audience invalide.');
        }

        $avatarFrame = is_string($input['avatarFrame'] ?? null) && '' !== $input['avatarFrame'] ? $input['avatarFrame'] : null;
        if (null !== $avatarFrame && !AvatarFrame::isValid($avatarFrame)) {
            $errors->add('avatarFrame', 'Cadre invalide.');
        } elseif (null !== $avatarFrame && !AvatarFrame::allowedFor($avatarFrame, $isAdmin, $this->cosmetics->ownedKeys($userId, CosmeticOwnershipInterface::FRAME))) {
            // Story 41.7: a shop frame is for who bought it, a legendary one for admins.
            $errors->add('avatarFrame', AvatarFrame::isLegendary($avatarFrame) ? 'Cadre réservé aux admins.' : 'Cadre à acheter en boutique.');
        }

        $socialLinks = $this->parseSocialLinks($input['socialLinks'] ?? null, $errors);
        $storedFavorites = $this->profiles->findByUserId($userId)?->getFavoriteGameIds() ?? [];
        $favoriteGameIds = $this->parseFavorites($input['favoriteGameIds'] ?? null, $errors, $storedFavorites);
        $showcaseLayout = $this->parseShowcaseLayout($input['showcaseLayout'] ?? null);

        $errorsArray = $errors->toArray();
        if ([] !== $errorsArray) {
            throw new ValidationException('Profil invalide.', $errorsArray);
        }

        $now = $this->clock->now();
        $profile = $this->profiles->findByUserId($userId);
        if (!$profile instanceof CommunityProfile) {
            $profile = CommunityProfile::create($userId, $now);
            $this->profiles->save($profile);
        }

        // An omitted audience keeps what the profile already holds; a profile created a line above holds
        // the entity default, so a first save without the field lands on Audience::DEFAULT.
        $audience = $audienceInput ?? $profile->getAudience();

        $favoriteGameIds = $this->keepHiddenFavorites($favoriteGameIds, $storedFavorites);
        $profile->customize($displayName, $bio, $tagline, $pronouns, $bannerPreset, $avatarFrame, $socialLinks, $favoriteGameIds, $audience, $showcaseLayout, $now);
        if (is_int($bannerOverlay)) {
            $profile->adjustBannerOverlay($bannerOverlay, $now);
        }
        if (null !== $avatarFraming) {
            $profile->reframeAvatar($avatarFraming, $now);
        }
        if (null !== $bannerFraming) {
            $profile->reframeBanner($bannerFraming, $now);
        }
        if (is_bool($titledName)) {
            $profile->toggleTitledName($titledName, $now);
        }
        $this->profiles->flush();
    }

    /**
     * @param array<string, mixed> $input
     */
    private function framing(array $input, string $field, ValidationErrors $errors): ?ImageFraming
    {
        if (!array_key_exists($field, $input) || null === $input[$field]) {
            return null;
        }
        $framing = ImageFraming::fromInput($input[$field]);
        if (null === $framing) {
            $errors->add($field, 'Cadrage invalide.');
        }

        return $framing;
    }

    /**
     * @return list<string> deduped, valid widget keys in the requested order
     */
    private function parseShowcaseLayout(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $layout = [];
        foreach ($raw as $widget) {
            if (is_string($widget) && ShowcaseWidget::isValid($widget) && !in_array($widget, $layout, true)) {
                $layout[] = $widget;
            }
        }

        return $layout;
    }

    private function nullableString(mixed $raw, int $max, string $field, ValidationErrors $errors): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);
        if ('' === $trimmed) {
            return null;
        }
        if (mb_strlen($trimmed) > $max) {
            $errors->add($field, sprintf('Trop long (%d caractères max).', $max));

            return null;
        }

        return $trimmed;
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private function parseSocialLinks(mixed $raw, ValidationErrors $errors): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $links = [];
        $index = 0;
        foreach ($raw as $entry) {
            if ($index >= self::MAX_SOCIAL_LINKS) {
                $errors->add('socialLinks', sprintf('%d liens maximum.', self::MAX_SOCIAL_LINKS));
                break;
            }
            if (!is_array($entry)) {
                continue;
            }

            $label = is_string($entry['label'] ?? null) ? trim($entry['label']) : '';
            $url = is_string($entry['url'] ?? null) ? trim($entry['url']) : '';

            if ('' === $label && '' === $url) {
                continue;
            }
            if ('' === $url || 1 !== preg_match('#^https?://#i', $url) || mb_strlen($url) > 300) {
                $errors->add(sprintf('socialLinks.%d.url', $index), 'Lien invalide (http(s):// requis, 300 max).');
                ++$index;
                continue;
            }
            if (mb_strlen($label) > 40) {
                $errors->add(sprintf('socialLinks.%d.label', $index), 'Label trop long (40 max).');
                ++$index;
                continue;
            }

            $links[] = ['label' => '' === $label ? $url : $label, 'url' => $url];
            ++$index;
        }

        return $links;
    }

    /**
     * @param list<string> $storedFavorites the favourites the profile holds, which may stay even if disabled
     *
     * @return list<string>
     */
    private function parseFavorites(mixed $raw, ValidationErrors $errors, array $storedFavorites = []): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $id) {
            if (is_string($id) && '' !== trim($id)) {
                $ids[] = trim($id);
            }
        }
        $ids = array_values(array_unique($ids));

        if (count($ids) > self::MAX_FAVORITE_GAMES) {
            $errors->add('favoriteGameIds', sprintf('%d jeux maximum.', self::MAX_FAVORITE_GAMES));
            $ids = array_slice($ids, 0, self::MAX_FAVORITE_GAMES);
        }

        if ([] !== $ids) {
            $found = [];
            foreach ($this->games->findByIds($ids) as $game) {
                $found[$game->getId()] = true;
                // Story 11.5: a disabled game cannot become a favourite (one already there may stay).
                if ($game->isDisabled() && !\in_array($game->getId(), $storedFavorites, true)) {
                    $errors->add('favoriteGameIds', sprintf('Jeu désactivé : %s', $game->getName()));
                }
            }
            foreach ($ids as $id) {
                if (!isset($found[$id])) {
                    $errors->add('favoriteGameIds', sprintf('Jeu inconnu : %s', $id));
                }
            }
        }

        return $ids;
    }

    /**
     * Story 11.5: a disabled favourite is hidden from the editor, which sends back what it shows - keep it, so it
     * comes back when the game is enabled again. Within the favourites limit.
     *
     * @param list<string> $favoriteGameIds
     * @param list<string> $storedFavorites
     *
     * @return list<string>
     */
    private function keepHiddenFavorites(array $favoriteGameIds, array $storedFavorites): array
    {
        $missing = array_values(array_diff($storedFavorites, $favoriteGameIds));
        if ([] === $missing) {
            return $favoriteGameIds;
        }
        foreach ($this->games->findByIds($missing) as $game) {
            if ($game->isDisabled() && \count($favoriteGameIds) < self::MAX_FAVORITE_GAMES) {
                $favoriteGameIds[] = $game->getId();
            }
        }

        return $favoriteGameIds;
    }
}
