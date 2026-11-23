<?php

declare(strict_types=1);

namespace App\Shared\Presentation\Controller;

use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 33.34: the version this API runs, so the deployment script checks the new release is the one served.
 * `APP_VERSION` is baked into the image at build (docker-publish.yml); a local or branch build says `dev`.
 */
final readonly class VersionController
{
    public function __construct(
        #[Autowire('%env(default::APP_VERSION)%')]
        private ?string $version,
    ) {
    }

    #[Route('/api/v1/version', name: 'api_version', methods: ['GET'])]
    public function __invoke(): JsonResponse
    {
        $version = null !== $this->version && '' !== $this->version ? $this->version : 'dev';

        return new JsonResponse(['data' => ['version' => $version]], 200, ['Cache-Control' => 'no-store']);
    }
}
