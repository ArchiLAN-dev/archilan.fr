<?php

declare(strict_types=1);

namespace App\Tests\Unit\Streaming;

use App\Streaming\Application\Service\MediaPublishSpikeGate;
use App\Streaming\Presentation\Controller\MediaAuthController;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpFoundation\Request;

/** Story 47.4 (spike): the fixed test key MediaMTX lets publishers in with. */
final class MediaPublishSpikeGateTest extends TestCase
{
    public function testWithoutAKeyTheEndpointDoesNotExist(): void
    {
        $controller = new MediaAuthController(new MediaPublishSpikeGate('', new NullLogger()));

        $response = $controller(Request::create('/api/v1/media-auth', 'POST', content: '{"action":"publish","path":"p-a","token":""}'));

        self::assertSame(404, $response->getStatusCode());
    }

    public function testTheKeyIsReadFromTheTokenOrThePassword(): void
    {
        $gate = new MediaPublishSpikeGate('k3y', new NullLogger());

        self::assertTrue($gate->allows(['action' => 'publish', 'path' => 'p-abc', 'token' => 'k3y']));
        self::assertTrue($gate->allows(['action' => 'publish', 'path' => 'p-abc', 'user' => 'x', 'password' => 'k3y']));
    }

    public function testOnlyAPublicationOnAPlayerPathWithTheKeyGoesThrough(): void
    {
        $gate = new MediaPublishSpikeGate('k3y', new NullLogger());

        self::assertFalse($gate->allows(['action' => 'read', 'path' => 'p-abc', 'token' => 'k3y']));
        self::assertFalse($gate->allows(['action' => 'publish', 'path' => 'regie', 'token' => 'k3y']));
        self::assertFalse($gate->allows(['action' => 'publish', 'path' => 'p-abc', 'token' => 'k3']));
        self::assertFalse($gate->allows(['action' => 'publish', 'path' => 'p-abc', 'token' => 42]));
        self::assertFalse($gate->allows([]));
    }
}
