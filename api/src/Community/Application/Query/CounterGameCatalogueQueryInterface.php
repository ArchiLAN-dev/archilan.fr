<?php

declare(strict_types=1);

namespace App\Community\Application\Query;

use App\Community\Domain\Enum\AchievementCounterKind;

/**
 * Story 30.57: the games a counter can aim at and the names of their checks and items, read from the catalogue
 * (`game`, `game_introspection`) without importing GameSelection. Names come from the introspection of the apworld;
 * for a game not introspected, from what its sessions put in the feed.
 */
interface CounterGameCatalogueQueryInterface
{
    /**
     * The catalogue's games that have an Archipelago name (the one the feed writes), by name; `introspected` when
     * the apworld gave its location and item names.
     *
     * @return list<array{id: string, name: string, archipelagoName: string, introspected: bool}>
     */
    public function games(): array;

    /**
     * Archipelago name of each of these games that has one.
     *
     * @param list<string> $gameIds
     *
     * @return array<string, string> game id => Archipelago name
     */
    public function archipelagoNames(array $gameIds): array;

    /**
     * Every name of one game for a kind of counter: the apworld's locations or items (with its item groups), or, with
     * no introspection, the names its sessions put in the feed (`source` = `feed`, no groups). Only that game's. Null
     * when the game is unknown or has no Archipelago name.
     *
     * @return array{source: 'introspection'|'feed', names: list<string>, groups: array<string, list<string>>}|null
     */
    public function names(string $gameId, AchievementCounterKind $kind): ?array;
}
