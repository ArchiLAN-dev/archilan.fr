<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Folds tutorial step `links` into the markdown `description` (story 31.11).
 *
 * `links` became redundant once descriptions are markdown, but the data must survive: seeded
 * catalogue links exist on most tutorials and would be lost by simply dropping the key. Each step is
 * read, transformed in PHP and written back; a step carrying no link is left byte-identical.
 *
 * The image fields are deliberately NOT folded: an uploaded image is a private MinIO object whose URL
 * is presigned at read time, so writing it into markdown would bake in an expiring URL. See the story
 * for the blocker.
 *
 * No schema change - these live inside JSON columns - so `down()` is a no-op: the folded markdown is
 * indistinguishable from hand-written markdown and cannot be mechanically split back out.
 */
final class Version20260722110000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Tutorial steps: fold links into the markdown description (story 31.11)';
    }

    public function up(Schema $schema): void
    {
        // Data-only migration; the work happens in postUp().
    }

    public function down(Schema $schema): void
    {
        // Irreversible by nature: the folded markdown cannot be told apart from authored markdown.
    }

    public function postUp(Schema $schema): void
    {
        $this->foldColumn('game', 'install_steps');
        $this->foldColumn('game_tutorial_contribution', 'steps');
    }

    private function foldColumn(string $table, string $column): void
    {
        $rows = $this->connection->fetchAllAssociative(
            sprintf('SELECT id, %s AS payload FROM %s WHERE %s IS NOT NULL', $column, $table, $column),
        );

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $payload = $row['payload'] ?? null;
            if (!is_string($id) || !is_string($payload)) {
                continue;
            }

            $decoded = json_decode($payload, true);
            if (!is_array($decoded)) {
                continue;
            }

            $changed = false;
            $steps = [];
            foreach ($decoded as $step) {
                if (!is_array($step)) {
                    $steps[] = $step;
                    continue;
                }

                $folded = self::foldStep($step);
                $changed = $changed || $folded !== $step;
                $steps[] = $folded;
            }

            if (!$changed) {
                continue;
            }

            $this->connection->update(
                $table,
                [$column => json_encode($steps, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)],
                ['id' => $id],
            );
        }
    }

    /**
     * @param array<string, mixed> $step
     *
     * @return array<string, mixed>
     */
    private static function foldStep(array $step): array
    {
        $description = is_string($step['description'] ?? null) ? rtrim($step['description']) : '';
        $blocks = '' === $description ? [] : [$description];

        $bullets = [];
        $links = $step['links'] ?? null;
        if (is_array($links)) {
            foreach ($links as $link) {
                if (!is_array($link)) {
                    continue;
                }
                $label = is_string($link['label'] ?? null) ? trim($link['label']) : '';
                $url = is_string($link['url'] ?? null) ? trim($link['url']) : '';
                if ('' === $label && '' === $url) {
                    continue;
                }
                // A link without a URL was already only a label; keep it as a plain bullet.
                $bullets[] = '' === $url
                    ? sprintf('- %s', $label)
                    : sprintf('- [%s](%s)', '' === $label ? $url : $label, $url);
            }
        }

        if ([] !== $bullets) {
            $blocks[] = implode("\n", $bullets);
        }

        unset($step['links']);
        $step['description'] = implode("\n\n", $blocks);

        return $step;
    }
}
