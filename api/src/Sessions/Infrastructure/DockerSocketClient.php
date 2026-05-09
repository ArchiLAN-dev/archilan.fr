<?php

declare(strict_types=1);

namespace App\Sessions\Infrastructure;

final readonly class DockerSocketClient
{
    /**
     * @param string $dockerHost  unix:///var/run/docker.sock  or  tcp://localhost:2375
     */
    public function __construct(
        private string $dockerHost = 'unix:///var/run/docker.sock',
    ) {
    }

    /**
     * Run an ephemeral container and return its exit code + combined output.
     *
     * @param list<string> $cmd
     * @param list<string> $binds  e.g. ['/host/path:/container/path']
     *
     * @return array{exitCode: int, output: string}
     */
    public function runEphemeral(
        string $image,
        string $entrypoint,
        array $cmd,
        array $binds,
        int $waitTimeout = 1200,
    ): array {
        $containerId = $this->createContainer($image, $entrypoint, $cmd, $binds);

        try {
            $this->startContainer($containerId);
            $exitCode = $this->waitContainer($containerId, $waitTimeout);
            $output = $this->getLogs($containerId);
        } finally {
            $this->removeContainer($containerId);
        }

        return ['exitCode' => $exitCode, 'output' => $output];
    }

    /** @return array<string, mixed> */
    public function inspect(string $nameOrId): array
    {
        return $this->jsonRequest('GET', '/containers/'.urlencode($nameOrId).'/json');
    }

    public function start(string $nameOrId): void
    {
        $this->rawRequest('POST', '/containers/'.urlencode($nameOrId).'/start');
    }

    public function stop(string $nameOrId, int $timeout = 5): void
    {
        $this->rawRequest('POST', '/containers/'.urlencode($nameOrId).'/stop?t='.$timeout, null, $timeout + 10);
    }

    public function restart(string $nameOrId, int $timeout = 5): void
    {
        $this->rawRequest('POST', '/containers/'.urlencode($nameOrId).'/restart?t='.$timeout, null, $timeout + 10);
    }

    public function remove(string $nameOrId, bool $force = false): void
    {
        $this->rawRequest('DELETE', '/containers/'.urlencode($nameOrId).'?force='.($force ? '1' : '0'));
    }

    public function tailLogs(string $nameOrId, int $tail = 300, bool $timestamps = true): string
    {
        $query = http_build_query(['stdout' => 1, 'stderr' => 1, 'tail' => $tail, 'timestamps' => (int) $timestamps]);
        $raw = $this->rawRequest('GET', '/containers/'.urlencode($nameOrId).'/logs?'.$query);

        return $this->demuxLogs($raw);
    }

    /**
     * Start a named persistent container and return its ID.
     *
     * @param list<string>            $binds   e.g. ['/host/path:/container/path:ro']
     * @param array<string, string>   $env     e.g. ['KEY' => 'value']
     * @param array<string, int>      $ports   e.g. ['38281/tcp' => 25000]
     */
    public function startPersistent(
        string $name,
        string $image,
        array $binds = [],
        array $env = [],
        array $ports = [],
    ): string {
        $exposedPorts = [];
        $portBindings = [];
        foreach ($ports as $containerPort => $hostPort) {
            $exposedPorts[$containerPort] = new \stdClass();
            $portBindings[$containerPort] = [['HostPort' => (string) $hostPort]];
        }

        $envList = [];
        foreach ($env as $key => $value) {
            $envList[] = $key.'='.$value;
        }

        $data = $this->jsonRequest('POST', '/containers/create?name='.urlencode($name), [
            'Image' => $image,
            'Env' => $envList,
            'ExposedPorts' => $exposedPorts,
            'HostConfig' => [
                'Binds' => $binds,
                'PortBindings' => $portBindings,
            ],
        ]);

        $id = is_string($data['Id'] ?? null) ? $data['Id'] : throw new \RuntimeException('Docker API returned no container ID.');
        $this->startContainer($id);

        return $id;
    }

    /**
     * @param list<string> $cmd
     * @param list<string> $binds
     */
    private function createContainer(string $image, string $entrypoint, array $cmd, array $binds): string
    {
        $data = $this->jsonRequest('POST', '/containers/create', [
            'Image' => $image,
            'Entrypoint' => [$entrypoint],
            'Cmd' => $cmd,
            'HostConfig' => ['Binds' => $binds],
        ]);

        return is_string($data['Id'] ?? null) ? $data['Id'] : throw new \RuntimeException('Docker API returned no container ID.');
    }

    private function startContainer(string $id): void
    {
        $this->rawRequest('POST', '/containers/'.$id.'/start');
    }

    private function waitContainer(string $id, int $timeout = 1200): int
    {
        $data = $this->jsonRequest('POST', '/containers/'.$id.'/wait', null, $timeout);

        $code = $data['StatusCode'] ?? 1;

        return is_int($code) ? $code : 1;
    }

    private function getLogs(string $id): string
    {
        $raw = $this->rawRequest('GET', '/containers/'.$id.'/logs?stdout=1&stderr=1');

        return $this->demuxLogs($raw);
    }

    private function removeContainer(string $id): void
    {
        try {
            $this->rawRequest('DELETE', '/containers/'.$id.'?force=1');
        } catch (\Throwable) {
        }
    }

    /**
     * @param non-empty-string          $method
     * @param array<string, mixed>|null $body
     *
     * @return array<string, mixed>
     */
    private function jsonRequest(string $method, string $path, ?array $body = null, int $timeout = 30): array
    {
        $raw = $this->rawRequest($method, $path, $body, $timeout);
        $decoded = json_decode($raw, true);

        /** @var array<string, mixed> */
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param non-empty-string          $method
     * @param array<string, mixed>|null $body
     *
     * @throws \RuntimeException on curl failure or 409 Conflict
     */
    private function rawRequest(string $method, string $path, ?array $body = null, int $timeout = 30): string
    {
        $ch = curl_init();

        if (str_starts_with($this->dockerHost, 'unix://')) {
            $socketPath = substr($this->dockerHost, 7);
            $socket = '' !== $socketPath ? $socketPath : null;
            curl_setopt($ch, \CURLOPT_URL, 'http://localhost'.$path);
            curl_setopt($ch, \CURLOPT_UNIX_SOCKET_PATH, $socket);
        } else {
            // 'tcp://host:port' → 'http://host:port', replace 'localhost' with '127.0.0.1' to skip DNS
            $authority = str_replace('localhost', '127.0.0.1', substr($this->dockerHost, 6));
            curl_setopt($ch, \CURLOPT_URL, 'http://'.$authority.$path);
        }

        curl_setopt($ch, \CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, \CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, \CURLOPT_TIMEOUT, $timeout);

        if (null !== $body) {
            $json = json_encode($body, \JSON_THROW_ON_ERROR);
            curl_setopt($ch, \CURLOPT_POSTFIELDS, $json);
            curl_setopt($ch, \CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, \CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!is_string($response)) {
            throw new \RuntimeException('Docker API request failed: '.$error);
        }

        if (409 === $httpCode) {
            throw new \RuntimeException('docker_conflict: '.$response);
        }

        return $response;
    }

    private function demuxLogs(string $raw): string
    {
        $output = '';
        $offset = 0;
        $length = \strlen($raw);

        while ($offset + 8 <= $length) {
            // 8-byte header: 1 byte stream type + 3 bytes padding + 4 bytes size (big-endian)
            $unpacked = unpack('N', substr($raw, $offset + 4, 4));
            $size = is_array($unpacked) && is_int($unpacked[1]) ? $unpacked[1] : 0;
            $offset += 8;

            if ($offset + $size > $length) {
                break;
            }

            $output .= substr($raw, $offset, $size);
            $offset += $size;
        }

        return $output;
    }
}
