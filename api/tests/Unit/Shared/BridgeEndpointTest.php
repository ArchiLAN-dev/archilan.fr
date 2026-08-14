<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Shared\Application\Support\BridgeEndpoint;
use PHPUnit\Framework\TestCase;

final class BridgeEndpointTest extends TestCase
{
    public function testBaseUrlTargetsTheContainerByName(): void
    {
        self::assertSame(
            'http://archilan-bridge-sess-1:5000',
            BridgeEndpoint::baseUrl('sess-1'),
        );
    }

    public function testTheAddressNeverDependsOnAHostPort(): void
    {
        // Le port hôte alloué à une session variait d'une run à l'autre et sortait par l'adresse
        // publique du serveur. L'adresse interne, elle, est entièrement dérivée de l'identifiant.
        $url = BridgeEndpoint::baseUrl('sess-2');

        self::assertStringContainsString(':5000', $url);
        self::assertStringNotContainsString('archilan.fr', $url);
        self::assertStringNotContainsString('localhost', $url);
    }

    public function testUrlAppendsThePathVerbatim(): void
    {
        self::assertSame(
            'http://archilan-bridge-sess-1:5000/state',
            BridgeEndpoint::url('sess-1', '/state'),
        );
        self::assertSame(
            'http://archilan-bridge-sess-1:5000/reachable/3',
            BridgeEndpoint::url('sess-1', '/reachable/3'),
        );
    }

    public function testTwoSessionsNeverShareAnAddress(): void
    {
        self::assertNotSame(
            BridgeEndpoint::baseUrl('sess-1'),
            BridgeEndpoint::baseUrl('sess-2'),
        );
    }
}
