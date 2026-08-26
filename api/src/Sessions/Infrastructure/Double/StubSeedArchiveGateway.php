<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure\Double;

use App\Sessions\Application\Port\SeedArchiveGatewayInterface;

/**
 * Test double for the orchestrator's seed-archive calls (story 16.18).
 *
 * Reading a multidata needs a real Archipelago container, so the tests cannot do it: what they can
 * pin is everything around it - who may import, what a refusal does, which slots become seats, and
 * that an imported run launches without generating anything.
 */
final class StubSeedArchiveGateway implements SeedArchiveGatewayInterface
{
    /** @var array{slots: list<array{slot: int, name: string, game: string, type: int}>, seedName: string}|array{error: string}|null */
    private static ?array $nextInspection = null;

    /** @var list<array{sessionId: string, outputKey: string, slotNames: list<array{name: string, game: string}>}> */
    private static array $launches = [];

    public static function reset(): void
    {
        self::$nextInspection = null;
        self::$launches = [];
    }

    /**
     * @param list<array{slot: int, name: string, game: string, type: int}> $slots
     */
    public static function willReturnSlots(array $slots, string $seedName = 'seed'): void
    {
        self::$nextInspection = ['slots' => $slots, 'seedName' => $seedName];
    }

    public static function willRefuse(string $error): void
    {
        self::$nextInspection = ['error' => $error];
    }

    /**
     * @return list<array{sessionId: string, outputKey: string, slotNames: list<array{name: string, game: string}>}>
     */
    public static function launches(): array
    {
        return self::$launches;
    }

    public function inspect(string $archive, string $filename): array
    {
        return self::$nextInspection ?? ['error' => 'not_configured'];
    }

    public function launchFromArchive(
        string $sessionId,
        string $outputKey,
        string $adminPassword,
        ?string $serverPassword,
        array $slotNames,
        array $serverOptions = [],
    ): void {
        self::$launches[] = ['sessionId' => $sessionId, 'outputKey' => $outputKey, 'slotNames' => $slotNames];
    }
}
