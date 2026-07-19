<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation\Controller;

use App\GameSelection\Application\Command\UpdateArchipelagoGuide;
use App\GameSelection\Application\Query\ArchipelagoGuideQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminArchipelagoGuideController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private UpdateArchipelagoGuide $command,
        private ArchipelagoGuideQuery $query,
    ) {
    }

    #[Route('/api/v1/admin/archipelago-guide', name: 'api_admin_archipelago_guide_update', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = json_decode($request->getContent(), true);
        $payload = is_array($payload) ? $payload : [];
        $steps = is_array($payload['steps'] ?? null) ? $payload['steps'] : [];

        // Validation failures are thrown as a ValidationException and mapped to HTTP by
        // ApplicationFailureListener (epic 35).
        $this->command->update($steps);

        return new JsonResponse(['data' => ['steps' => $this->query->steps()], 'meta' => ['message' => 'Guide enregistré.']]);
    }
}
