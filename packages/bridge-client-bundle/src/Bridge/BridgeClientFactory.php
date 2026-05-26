<?php

declare(strict_types=1);

namespace Archilan\BridgeClientBundle\Bridge;

use Archilan\BridgeClient\BridgeClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Creates BridgeClient instances for any bridge URL.
 *
 * The admin token is shared across all sessions (single env var).
 * The base URL is dynamic — obtained at runtime from the orchestrateur session data.
 *
 * Inject this factory wherever you need a bridge client on demand:
 *
 *   $client = $factory->create("http://localhost:{$session->bridgePort}");
 */
final class BridgeClientFactory
{
    public function __construct(
        private readonly string $adminToken,
        private readonly HttpClientInterface $httpClient,
    ) {
    }

    public function create(string $baseUrl): BridgeClient
    {
        return new BridgeClient($baseUrl, $this->adminToken, $this->httpClient);
    }
}
