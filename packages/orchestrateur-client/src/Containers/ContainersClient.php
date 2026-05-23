<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Containers;

use Archilan\OrchestratorClient\Containers\Response\ContainerResponse;
use Archilan\OrchestratorClient\Containers\Response\CreateContainerResult;
use Archilan\OrchestratorClient\Exception\OrchestratorException;
use Archilan\OrchestratorClient\Http\HttpTransport;

final class ContainersClient
{
    public function __construct(private readonly HttpTransport $transport)
    {
    }

    public function create(
        string $sessionId,
        string $adminPassword,
        string $serverPassword = '',
    ): CreateContainerResult {
        return CreateContainerResult::fromArray($this->transport->postJson('/containers', [
            'sessionId' => $sessionId,
            'adminPassword' => $adminPassword,
            'serverPassword' => $serverPassword,
        ]));
    }

    /**
     * @return ContainerResponse[]
     */
    public function list(): array
    {
        $data = $this->transport->getJson('/containers');
        $rawList = $data['containers'] ?? [];
        if (!is_array($rawList)) {
            throw new OrchestratorException("Missing or invalid field 'containers' in list response");
        }

        $result = [];
        foreach ($rawList as $item) {
            if (is_array($item)) {
                /** @var array<string, mixed> $item */
                $result[] = ContainerResponse::fromArray($item);
            }
        }

        return $result;
    }

    public function get(string $sessionId): ContainerResponse
    {
        return ContainerResponse::fromArray($this->transport->getJson("/containers/{$sessionId}"));
    }

    public function stop(string $sessionId): void
    {
        $this->transport->postVoid("/containers/{$sessionId}/stop");
    }

    public function reload(string $sessionId): void
    {
        $this->transport->postVoid("/containers/{$sessionId}/reload");
    }

    public function remove(string $sessionId): void
    {
        $this->transport->deleteVoid("/containers/{$sessionId}");
    }
}
