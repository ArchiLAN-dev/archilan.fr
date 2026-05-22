<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\Sessions\Domain\Session;
use App\Sessions\Domain\SessionRepositoryInterface;

final readonly class TraefikConfigBuilder
{
    public function __construct(
        private SessionRepositoryInterface $sessions,
        private string $wsDomain,
    ) {
    }

    /**
     * Returns a Traefik HTTP provider config for all running sessions.
     *
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $sessions = $this->sessions->findByStatus(Session::STATUS_RUNNING);

        $routers = [];
        $services = [];

        foreach ($sessions as $session) {
            $host = $session->getHost();
            $port = $session->getPort();

            if (null === $host || null === $port) {
                continue;
            }

            $key = 'run-'.$session->getId();
            $hostname = $session->getId().'.'.$this->wsDomain;

            $routers[$key] = [
                'rule' => sprintf('Host(`%s`)', $hostname),
                'service' => $key,
                'entryPoints' => ['websecure'],
                'tls' => new \stdClass(),
            ];

            $services[$key] = [
                'loadBalancer' => [
                    'servers' => [
                        ['url' => sprintf('http://%s:%d', $host, $port)],
                    ],
                ],
            ];
        }

        return [
            'http' => [
                'routers' => $routers ?: new \stdClass(),
                'services' => $services ?: new \stdClass(),
            ],
        ];
    }
}
