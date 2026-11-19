<?php

declare(strict_types=1);

namespace App\Trackers\Infrastructure\Dbal;

use App\Trackers\Application\Query\AdminTrackerPackQueryInterface;
use App\Trackers\Application\Query\AdminTrackerPackView;
use App\Trackers\Domain\ValueObject\TrackerPackUpdate;
use App\Trackers\Domain\ValueObject\TrackerPackVariant;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;

final readonly class DbalAdminTrackerPackQuery implements AdminTrackerPackQueryInterface
{
    public function __construct(private Connection $connection)
    {
    }

    public function forGame(string $gameId): array
    {
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select(
                'p.id', 'p.name', 'p.author', 'p.package_uid', 'p.game_name', 'p.source_url', 'p.license_spdx',
                'p.enabled', 'p.is_default', 'p.default_variant_uid', 'p.license_acknowledged',
                'p.available_update', 'p.last_checked_at', 'p.last_check_error',
                'v.id AS version_id', 'v.package_version', 'v.git_tag', 'v.size_bytes', 'v.variants',
                'v.min_poptracker_version', 'v.imported_at', 'v.root_path', 'v.sha256',
            )
            ->from('tracker_pack', 'p')
            ->innerJoin('p', 'tracker_pack_version', 'v', $qb->expr()->eq('v.id', 'p.current_version_id'))
            ->where($qb->expr()->eq('p.game_id', ':gameId'))
            ->setParameter('gameId', $gameId)
            ->orderBy('p.created_at', 'ASC')
            ->addOrderBy('p.id', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $versions = $this->versions(array_map(static fn (array $row): string => self::text($row['id']), $rows));
        $tries = $this->compatibility(array_map(static fn (array $row): string => self::text($row['version_id']), $rows));
        $views = [];
        foreach ($rows as $row) {
            $packId = self::text($row['id']);
            $variants = TrackerPackVariantRows::decode($row['variants']);
            $views[] = new AdminTrackerPackView(
                $packId,
                self::text($row['name']),
                self::nullableText($row['author']),
                self::text($row['package_uid']),
                self::nullableText($row['game_name']),
                self::text($row['source_url']),
                self::nullableText($row['license_spdx']),
                [] !== array_filter($variants, static fn (array $v): bool => in_array(TrackerPackVariant::ARCHIPELAGO_FLAG, $v['flags'], true)),
                self::text($row['version_id']),
                self::nullableText($row['package_version']),
                self::nullableText($row['git_tag']),
                is_numeric($row['size_bytes']) ? (int) $row['size_bytes'] : 0,
                $variants,
                self::nullableText($row['min_poptracker_version']),
                self::date($row['imported_at']) ?? '',
                true === $row['enabled'],
                true === $row['is_default'],
                self::nullableText($row['default_variant_uid']),
                true === $row['license_acknowledged'],
                self::update($row['available_update']),
                self::date($row['last_checked_at']),
                self::nullableText($row['last_check_error']),
                array_map(
                    static fn (array $v): array => [...$v, 'current' => $v['id'] === self::text($row['version_id'])],
                    $versions[$packId] ?? [],
                ),
                self::text($row['root_path']),
                self::text($row['sha256']),
                $tries[self::text($row['version_id'])] ?? [],
            );
        }

        return $views;
    }

    public function sourceUrlsByGame(): array
    {
        $rows = $this->connection->createQueryBuilder()
            ->select('p.game_id', 'p.source_url')
            ->from('tracker_pack', 'p')
            ->executeQuery()
            ->fetchAllAssociative();

        $byGame = [];
        foreach ($rows as $row) {
            $byGame[self::text($row['game_id'])][] = self::text($row['source_url']);
        }

        return $byGame;
    }

    /**
     * @param list<string> $packIds
     *
     * @return array<string, list<array{id: string, packageVersion: ?string, gitTag: ?string, sizeBytes: int, importedAt: string, rootPath: string, sha256: string, variants: list<array{uid: string, displayName: string, flags: list<string>}>}>>
     */
    private function versions(array $packIds): array
    {
        if ([] === $packIds) {
            return [];
        }
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('v.id', 'v.pack_id', 'v.package_version', 'v.git_tag', 'v.size_bytes', 'v.imported_at', 'v.root_path', 'v.sha256', 'v.variants')
            ->from('tracker_pack_version', 'v')
            ->where($qb->expr()->in('v.pack_id', ':packIds'))
            ->setParameter('packIds', $packIds, ArrayParameterType::STRING)
            ->orderBy('v.imported_at', 'DESC')
            ->addOrderBy('v.id', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        $byPack = [];
        foreach ($rows as $row) {
            $byPack[self::text($row['pack_id'])][] = [
                'id' => self::text($row['id']),
                'packageVersion' => self::nullableText($row['package_version']),
                'gitTag' => self::nullableText($row['git_tag']),
                'sizeBytes' => is_numeric($row['size_bytes']) ? (int) $row['size_bytes'] : 0,
                'importedAt' => self::date($row['imported_at']) ?? '',
                'rootPath' => self::text($row['root_path']),
                'sha256' => self::text($row['sha256']),
                'variants' => TrackerPackVariantRows::decode($row['variants']),
            ];
        }

        return $byPack;
    }

    /**
     * Story 54.8: the last try of each variant, by version.
     *
     * @param list<string> $versionIds
     *
     * @return array<string, list<array{variantUid: string, status: string, errors: list<array{kind: string, message: string, file: ?string, line: ?int}>, unsupportedApis: list<string>, engineVersion: int, reportedAt: string}>>
     */
    private function compatibility(array $versionIds): array
    {
        if ([] === $versionIds) {
            return [];
        }
        $qb = $this->connection->createQueryBuilder();
        $rows = $qb
            ->select('c.version_id', 'c.variant_uid', 'c.status', 'c.errors', 'c.unsupported_apis', 'c.engine_version', 'c.reported_at')
            ->from('tracker_pack_compatibility', 'c')
            ->where($qb->expr()->in('c.version_id', ':versionIds'))
            ->setParameter('versionIds', $versionIds, ArrayParameterType::STRING)
            ->orderBy('c.variant_uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        $byVersion = [];
        foreach ($rows as $row) {
            $byVersion[self::text($row['version_id'])][] = [
                'variantUid' => self::text($row['variant_uid']),
                'status' => self::text($row['status']),
                'errors' => self::errors($row['errors']),
                'unsupportedApis' => self::strings($row['unsupported_apis']),
                'engineVersion' => is_numeric($row['engine_version']) ? (int) $row['engine_version'] : 0,
                'reportedAt' => self::date($row['reported_at']) ?? '',
            ];
        }

        return $byVersion;
    }

    /** @return list<array{kind: string, message: string, file: ?string, line: ?int}> */
    private static function errors(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        $errors = [];
        foreach (is_array($decoded) ? $decoded : [] as $error) {
            if (!is_array($error)) {
                continue;
            }
            $errors[] = [
                'kind' => self::text($error['kind'] ?? null),
                'message' => self::text($error['message'] ?? null),
                'file' => self::nullableText($error['file'] ?? null),
                'line' => is_int($error['line'] ?? null) ? $error['line'] : null,
            ];
        }

        return $errors;
    }

    /** @return list<string> */
    private static function strings(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return array_values(array_filter(is_array($decoded) ? $decoded : [], is_string(...)));
    }

    /** @return array{packageVersion: string, downloadUrl: string, sha256: ?string, changelog: list<string>, gitTag: ?string}|null */
    private static function update(mixed $raw): ?array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : null;

        return is_array($decoded) ? TrackerPackUpdate::fromArray($decoded)?->toArray() : null;
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) ? new \DateTimeImmutable($value)->format(\DATE_ATOM) : null;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private static function nullableText(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }
}
