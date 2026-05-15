<?php

declare(strict_types=1);

namespace App\Identity\Application;

use Doctrine\DBAL\Connection;
use Symfony\Component\String\Slugger\AsciiSlugger;

final readonly class SlugGenerator
{
    public function __construct(private Connection $connection)
    {
    }

    public static function normalize(string $source): string
    {
        $normalized = (string) (new AsciiSlugger())->slug($source)->lower();

        if ('' === $normalized) {
            return 'user';
        }

        // Cap at 75 chars: leaves room for a '-NNNN' collision suffix within VARCHAR(80)
        return mb_substr($normalized, 0, 75);
    }

    /**
     * @param callable(string): bool $existsCheck
     */
    public static function generate(string $source, callable $existsCheck): string
    {
        $base = self::normalize($source);
        $slug = $base;
        $i = 2;
        while ($existsCheck($slug)) {
            $slug = $base.'-'.$i;
            ++$i;
        }

        return $slug;
    }

    public function generateForUser(string $source): string
    {
        return self::generate($source, fn (string $s) => $this->slugExists($s));
    }

    private function slugExists(string $slug): bool
    {
        return false !== $this->connection->fetchOne(
            'SELECT 1 FROM identity_users WHERE slug = ?',
            [$slug],
        );
    }
}
