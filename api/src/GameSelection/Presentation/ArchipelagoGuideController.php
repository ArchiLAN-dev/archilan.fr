<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation;

use App\GameSelection\Application\ArchipelagoGuideQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class ArchipelagoGuideController
{
    public function __construct(private ArchipelagoGuideQuery $query)
    {
    }

    #[Route('/api/v1/archipelago-guide', name: 'api_archipelago_guide_public', methods: ['GET'])]
    public function get(): JsonResponse
    {
        return new JsonResponse(['data' => ['steps' => $this->query->steps()]]);
    }
}
