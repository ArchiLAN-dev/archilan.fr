<?php

declare(strict_types=1);

namespace App\Sessions\Application;

use App\Sessions\Domain\Session;
use Doctrine\ORM\EntityManagerInterface;

final readonly class TraefikConfigBuilder
{
    public function __construct(
        private EntityManagerInterface $entityManager,
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
        /** @var list<Session> $sessions */
        $sessions = $this->entityManager->createQueryBuilder()
            ->select('s')
            ->from(Session::class, 's')
            ->where('s.status = :status')
            ->setParameter('status', Session::STATUS_RUNNING)
            ->getQuery()
            ->getResult();

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
