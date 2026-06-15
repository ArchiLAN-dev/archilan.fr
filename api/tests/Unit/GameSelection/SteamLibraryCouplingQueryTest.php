<?php

declare(strict_types=1);

namespace App\Tests\Unit\GameSelection;

use App\GameSelection\Application\SteamCatalogQueryInterface;
use App\GameSelection\Application\SteamLibraryCouplingQuery;
use App\GameSelection\Infrastructure\SteamApiException;
use App\GameSelection\Infrastructure\SteamWebApiClientInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class SteamLibraryCouplingQueryTest extends TestCase
{
    private const STEAM_ID = '76561197960287930';

    public function testOkIntersectsOwnedAppIdsWithCatalog(): void
    {
        $steam = $this->createStub(SteamWebApiClientInterface::class);
        $steam->method('fetchOwnedAppIds')->willReturn(['visibility' => 'public', 'appIds' => [10, 20]]);

        $query = new SteamLibraryCouplingQuery($steam, $this->catalogWith(10, 30), $this->createStub(LoggerInterface::class));

        $result = $query->couple(self::STEAM_ID);

        self::assertSame('ok', $result['outcome']);
        self::assertSame(2, $result['ownedCount']);
        self::assertSame(1, $result['matchedCount']);
        self::assertCount(1, $result['matchedGames']);
        self::assertSame(10, $result['matchedGames'][0]['steamAppId']);
    }

    public function testInvalidInputForUnparseableProfile(): void
    {
        $query = new SteamLibraryCouplingQuery(
            $this->createStub(SteamWebApiClientInterface::class),
            $this->catalogWith(10),
            $this->createStub(LoggerInterface::class),
        );

        self::assertSame('invalid_input', $query->couple('not a profile !!')['outcome']);
    }

    public function testInvalidInputWhenVanityDoesNotResolve(): void
    {
        $steam = $this->createStub(SteamWebApiClientInterface::class);
        $steam->method('resolveVanityUrl')->willReturn(null);

        $query = new SteamLibraryCouplingQuery($steam, $this->catalogWith(10), $this->createStub(LoggerInterface::class));

        self::assertSame('invalid_input', $query->couple('ghost')['outcome']);
    }

    public function testPrivateProfileYieldsNoMatches(): void
    {
        $steam = $this->createStub(SteamWebApiClientInterface::class);
        $steam->method('fetchOwnedAppIds')->willReturn(['visibility' => 'private', 'appIds' => []]);

        $query = new SteamLibraryCouplingQuery($steam, $this->catalogWith(10), $this->createStub(LoggerInterface::class));

        $result = $query->couple(self::STEAM_ID);

        self::assertSame('private_profile', $result['outcome']);
        self::assertSame(0, $result['matchedCount']);
    }

    public function testSteamErrorWhenClientThrows(): void
    {
        $steam = $this->createStub(SteamWebApiClientInterface::class);
        $steam->method('fetchOwnedAppIds')->willThrowException(new SteamApiException('boom'));

        $query = new SteamLibraryCouplingQuery($steam, $this->catalogWith(10), $this->createStub(LoggerInterface::class));

        self::assertSame('steam_error', $query->couple(self::STEAM_ID)['outcome']);
    }

    private function catalogWith(int ...$steamAppIds): SteamCatalogQueryInterface
    {
        $games = array_map(
            static fn (int $appId): array => [
                'id' => 'g'.$appId,
                'name' => 'Game '.$appId,
                'slug' => 'game-'.$appId,
                'coverImageUrl' => null,
                'availability' => 'available',
                'steamAppId' => $appId,
            ],
            $steamAppIds,
        );

        $catalog = $this->createStub(SteamCatalogQueryInterface::class);
        $catalog->method('allWithSteamAppId')->willReturn(array_values($games));

        return $catalog;
    }
}
