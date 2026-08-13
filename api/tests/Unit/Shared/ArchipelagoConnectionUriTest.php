<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Application\Support\ArchipelagoConnectionUri;
use PHPUnit\Framework\TestCase;

final class ArchipelagoConnectionUriTest extends TestCase
{
    public function testBuildProducesASecureWebSocketUri(): void
    {
        self::assertSame(
            'wss://orchestrateur.archilan.fr:35042',
            ArchipelagoConnectionUri::build('orchestrateur.archilan.fr', 35042),
        );
    }

    public function testSchemeIsAlwaysSecure(): void
    {
        // Une page HTTPS ne peut pas ouvrir un WebSocket en clair : `ws://` ne serait joignable
        // par aucun client web, sans message d'erreur exploitable côté joueur.
        self::assertStringStartsWith('wss://', ArchipelagoConnectionUri::build('example.org', 1));
    }

    public function testTryBuildReturnsNullWhenTheRunHasNoEndpointYet(): void
    {
        self::assertNull(ArchipelagoConnectionUri::tryBuild(null, null));
        self::assertNull(ArchipelagoConnectionUri::tryBuild('example.org', null));
        self::assertNull(ArchipelagoConnectionUri::tryBuild(null, 35042));
    }

    public function testTryBuildRejectsAnEmptyHost(): void
    {
        // Le stockage historique met une chaîne vide quand l'hôte est inconnu : la laisser passer
        // produirait `wss://:35042`, une adresse que le joueur copierait sans comprendre l'erreur.
        self::assertNull(ArchipelagoConnectionUri::tryBuild('', 35042));
        self::assertNull(ArchipelagoConnectionUri::tryBuild('   ', 35042));
    }

    public function testTryBuildRejectsANonAllocatedPort(): void
    {
        self::assertNull(ArchipelagoConnectionUri::tryBuild('example.org', 0));
        self::assertNull(ArchipelagoConnectionUri::tryBuild('example.org', -1));
    }

    public function testTryBuildMatchesBuildWhenBothAreKnown(): void
    {
        self::assertSame(
            ArchipelagoConnectionUri::build('example.org', 35042),
            ArchipelagoConnectionUri::tryBuild('example.org', 35042),
        );
    }
}
