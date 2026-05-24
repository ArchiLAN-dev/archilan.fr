<?php

declare(strict_types=1);

namespace Archilan\OrchestratorClient\Sessions;

use Archilan\OrchestratorClient\Http\HttpTransport;
use Archilan\OrchestratorClient\Sessions\Request\ConfigureRequest;
use Archilan\OrchestratorClient\Sessions\Request\PreflightRequest;
use Archilan\OrchestratorClient\Sessions\Response\ConfigureResult;
use Archilan\OrchestratorClient\Sessions\Response\PreflightResult;
use Archilan\OrchestratorClient\Sessions\Response\SessionResponse;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;

final class SessionsClient
{
    public function __construct(private readonly HttpTransport $transport)
    {
    }

    public function generate(string $sessionId, string $adminPassword, ?string $seed = null): void
    {
        $body = ['adminPassword' => $adminPassword];
        if (null !== $seed) {
            $body['seed'] = $seed;
        }
        $this->transport->postVoid("/sessions/{$sessionId}/generate", $body);
    }

    public function launch(string $sessionId, string $adminPassword, ?string $serverPassword = null): void
    {
        $body = ['adminPassword' => $adminPassword];
        if (null !== $serverPassword) {
            $body['serverPassword'] = $serverPassword;
        }
        $this->transport->postVoid("/sessions/{$sessionId}/launch", $body);
    }

    public function launchFromFile(
        string $sessionId,
        string $fileContents,
        string $filename,
        string $adminPassword,
        ?string $serverPassword = null,
    ): void {
        $fields = [
            'file' => new DataPart($fileContents, $filename, 'application/octet-stream'),
            'adminPassword' => $adminPassword,
        ];
        if (null !== $serverPassword) {
            $fields['serverPassword'] = $serverPassword;
        }
        $this->transport->postMultipartVoid(
            "/sessions/{$sessionId}/launch-from-file",
            new FormDataPart($fields),
        );
    }

    public function stop(string $sessionId): void
    {
        $this->transport->postVoid("/sessions/{$sessionId}/stop");
    }

    public function restart(string $sessionId): void
    {
        $this->transport->postVoid("/sessions/{$sessionId}/restart");
    }

    public function get(string $sessionId): SessionResponse
    {
        return SessionResponse::fromArray($this->transport->getJson("/sessions/{$sessionId}"));
    }

    public function delete(string $sessionId): void
    {
        $this->transport->deleteVoid("/sessions/{$sessionId}");
    }

    public function preflight(string $sessionId, PreflightRequest $request): PreflightResult
    {
        return PreflightResult::fromArray(
            $this->transport->postJson("/sessions/{$sessionId}/preflight", $request),
        );
    }

    public function configure(string $sessionId, ConfigureRequest $request): ConfigureResult
    {
        return ConfigureResult::fromArray(
            $this->transport->postJson("/sessions/{$sessionId}/configure", $request),
        );
    }
}
