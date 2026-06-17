<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Data repair (epic 10, story 10.9): rewrite frozen presigned gallery URLs back
 * into re-signable upload keys.
 *
 * A defect in AdminEventDrafts::reconcilePhotoGallery() stored uploaded gallery
 * photos as {source: 'url', url: '<presigned MinIO URL>'} instead of
 * {source: 'upload', key: '...'}. Those frozen URLs expire ~1h after the last
 * admin save, breaking the public gallery. This migration converts every gallery
 * entry whose URL points at one of our managed gallery objects
 * (events/{eventId}/gallery/{file}) back into an upload key, so it is re-signed
 * on each read. Entries pointing elsewhere are left untouched.
 */
final class Version20260617220001 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Repair event photo_gallery: convert frozen presigned URLs to re-signable upload keys (story 10.9)';
    }

    public function up(Schema $schema): void
    {
        $rows = $this->connection->fetchAllAssociative('SELECT id, photo_gallery FROM event WHERE photo_gallery IS NOT NULL');

        foreach ($rows as $row) {
            $id = $row['id'] ?? null;
            $rawGallery = $row['photo_gallery'] ?? null;
            if (!is_string($id) || !is_string($rawGallery)) {
                continue;
            }

            $decoded = json_decode($rawGallery, true);
            if (!is_array($decoded)) {
                continue;
            }

            $repaired = [];
            $changed = false;
            foreach ($decoded as $item) {
                [$newItem, $itemChanged] = $this->repairItem($item);
                $repaired[] = $newItem;
                $changed = $changed || $itemChanged;
            }

            if (!$changed) {
                continue;
            }

            $this->addSql(
                'UPDATE event SET photo_gallery = :gallery WHERE id = :id',
                ['gallery' => json_encode($repaired, JSON_THROW_ON_ERROR), 'id' => $id],
            );
        }
    }

    public function down(Schema $schema): void
    {
        // No-op: the original frozen presigned URLs were already expired and are
        // not worth restoring. Re-signable upload keys are strictly better.
    }

    /**
     * @return array{0: mixed, 1: bool} the (possibly repaired) item and whether it changed
     */
    private function repairItem(mixed $item): array
    {
        $url = null;
        if (is_string($item)) {
            $url = $item;
        } elseif (is_array($item) && 'url' === ($item['source'] ?? null) && is_string($item['url'] ?? null)) {
            $url = $item['url'];
        }

        if (null === $url) {
            return [$item, false];
        }

        $key = $this->galleryObjectKey($url);
        if (null === $key) {
            return [$item, false];
        }

        return [['source' => 'upload', 'key' => $key], true];
    }

    private function galleryObjectKey(string $url): ?string
    {
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            return null;
        }

        if (1 !== preg_match('#/(events/[^/]+/gallery/[^/?]+)$#', $path, $matches)) {
            return null;
        }

        return rawurldecode($matches[1]);
    }
}
