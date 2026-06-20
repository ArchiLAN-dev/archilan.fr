<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation;

use App\GameSelection\Application\ArchipelagoClientQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ArchipelagoClientController
{
    public function __construct(private ArchipelagoClientQuery $query)
    {
    }

    #[Route('/api/v1/archipelago-client', name: 'api_archipelago_client_public', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return new JsonResponse(['data' => $this->query->get()]);
    }
}
