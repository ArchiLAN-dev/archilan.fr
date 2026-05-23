<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient;

use Archilan\OrchestratorClient\Apworlds\ApworldsClient;
use Archilan\OrchestratorClient\Containers\ContainersClient;
use Archilan\OrchestratorClient\Http\HttpTransport;
use Archilan\OrchestratorClient\Sessions\SessionsClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

final class OrchestratorClient
{
    private readonly HttpTransport $transport;
    private readonly SessionsClient $sessions;
    private readonly ContainersClient $containers;
    private readonly ApworldsClient $apworlds;

    public function __construct(
        string $baseUrl,
        string $apiKey,
        HttpClientInterface $httpClient,
    ) {
        $this->transport = new HttpTransport($httpClient, $baseUrl, $apiKey);
        $this->sessions = new SessionsClient($this->transport);
        $this->containers = new ContainersClient($this->transport);
        $this->apworlds = new ApworldsClient($this->transport);
    }

    public function sessions(): SessionsClient
    {
        return $this->sessions;
    }

    public function containers(): ContainersClient
    {
        return $this->containers;
    }

    public function apworlds(): ApworldsClient
    {
        return $this->apworlds;
    }
}
