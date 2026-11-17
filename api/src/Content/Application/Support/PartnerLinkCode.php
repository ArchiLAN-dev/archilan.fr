<?php

declare(strict_types=1);

namespace App\Content\Application\Support;

use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * Story 55.3: the code of a partner's tracked link, `partenaire-{name}`, then `-2`, `-3`... when taken. It fits the
 * link code rule (40 characters at most, no dash at either end). The catch-up migration Version20261117140000 repeats
 * this rule on its own: a migration never depends on application code that may change.
 */
final class PartnerLinkCode
{
    private const string PREFIX = 'partenaire';
    private const int MAX_LENGTH = 40;

    /** The `$attempt`-th candidate for that name: 1 is the plain one, then `-2`, `-3`... */
    public static function candidate(string $partnerName, int $attempt): string
    {
        $slug = (string) new AsciiSlugger()->slug($partnerName)->lower();
        $base = '' === $slug ? self::PREFIX : self::PREFIX.'-'.$slug;
        $suffix = $attempt > 1 ? '-'.$attempt : '';

        return rtrim(substr($base, 0, self::MAX_LENGTH - strlen($suffix)), '-').$suffix;
    }
}
