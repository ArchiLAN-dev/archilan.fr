<?php

declare(strict_types=1);

namespace App\GameSelection\Application;

interface GameCatalogQueryInterface
{
    /**
     * @return array{items: list<array<string, mixed>>, total: int, page: int, perPage: int, totalPages: int}
     */
    public function list(string $query = '', int $page = 1): array;

    /**
     * Full catalog (no pagination) for the client-driven Jeux page.
     *
     * @return list<array<string, mixed>>
     */
    public function all(string $query = ''): array;

    /**
     * Detail view of a single public game (availability available/experimental).
     *
     * Returns null when no published game matches the slug. The `catalogSheetName` and
     * `archipelagoGameName` keys are match hints for sheet-metadata resolution and are not
     * part of the public payload.
     *
     * @return array{
     *   id: string,
     *   name: string,
     *   slug: string,
     *   description: string,
     *   coverImageUrl: string|null,
     *   coverImageAlt: string,
     *   coverImageCredit: string,
     *   availability: string,
     *   steamAppId: int|null,
     *   platforms: list<string>,
     *   supportedEventTypes: list<string>,
     *   archipelagoGameName: string|null,
     *   catalogSheetName: string|null,
     *   apworld: array{deployedVersion: string|null, latestVersion: string|null, sourceUrl: string|null, releaseUrl: string|null, updateStatus: string},
     *   options: list<array{key: string, min: int, max: int, default: int|null}>
     * }|null
     */
    public function bySlug(string $slug): ?array;
}
