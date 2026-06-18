<?php

declare(strict_types=1);

namespace App\Community\Application;

use App\Community\Domain\Audience;
use App\Community\Domain\BannerPreset;
use App\Community\Domain\CommunityProfile;
use App\Community\Domain\CommunityProfileRepositoryInterface;
use App\Community\Domain\ShowcaseWidget;
use App\GameSelection\Domain\GameRepositoryInterface;
use App\Identity\Application\ValidationErrors;

/**
 * Owner edit of their own community profile customization (story 30.3). Upserts the profile row and
 * applies validated customization. Returns a validation result; never throws on bad input.
 */
final readonly class UpdateCommunityProfile
{
    private const MAX_SOCIAL_LINKS = 5;
    private const MAX_FAVORITE_GAMES = 6;

    public function __construct(
        private CommunityProfileRepositoryInterface $profiles,
        private GameRepositoryInterface $games,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array{errorCode: string|null, errors: array<string, list<string>>}
     */
    public function update(string $userId, array $input): array
    {
        $errors = new ValidationErrors();

        $displayName = $this->nullableString($input['displayName'] ?? null, 80, 'displayName', $errors);
        $bio = $this->nullableString($input['bio'] ?? null, 2000, 'bio', $errors);
        $tagline = $this->nullableString($input['tagline'] ?? null, 120, 'tagline', $errors);
        $pronouns = $this->nullableString($input['pronouns'] ?? null, 40, 'pronouns', $errors);

        $bannerPreset = is_string($input['bannerPreset'] ?? null) ? $input['bannerPreset'] : BannerPreset::DEFAULT;
        if (!BannerPreset::isValid($bannerPreset)) {
            $errors->add('bannerPreset', 'Bannière invalide.');
        }

        $audience = is_string($input['audience'] ?? null) ? $input['audience'] : Audience::MEMBERS;
        if (!Audience::isValid($audience)) {
            $errors->add('audience', 'Audience invalide.');
        }

        $socialLinks = $this->parseSocialLinks($input['socialLinks'] ?? null, $errors);
        $favoriteGameIds = $this->parseFavorites($input['favoriteGameIds'] ?? null, $errors);
        $showcaseLayout = $this->parseShowcaseLayout($input['showcaseLayout'] ?? null);

        $errorsArray = $errors->toArray();
        if ([] !== $errorsArray) {
            return ['errorCode' => 'validation_failed', 'errors' => $errorsArray];
        }

        $now = new \DateTimeImmutable();
        $profile = $this->profiles->findByUserId($userId);
        if (!$profile instanceof CommunityProfile) {
            $profile = CommunityProfile::create($userId, $now);
            $this->profiles->save($profile);
        }

        $profile->customize($displayName, $bio, $tagline, $pronouns, $bannerPreset, $socialLinks, $favoriteGameIds, $audience, $showcaseLayout, $now);
        $this->profiles->flush();

        return ['errorCode' => null, 'errors' => []];
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
     * @return list<string>
     */
    private function parseFavorites(mixed $raw, ValidationErrors $errors): array
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
            }
            foreach ($ids as $id) {
                if (!isset($found[$id])) {
                    $errors->add('favoriteGameIds', sprintf('Jeu inconnu : %s', $id));
                }
            }
        }

        return $ids;
    }
}
