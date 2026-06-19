<?php

declare(strict_types=1);

namespace App\GameSelection\Presentation;

use App\GameSelection\Application\ArchipelagoClientQuery;
use App\GameSelection\Application\UpdateArchipelagoClient;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminArchipelagoClientController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private UpdateArchipelagoClient $command,
        private ArchipelagoClientQuery $query,
    ) {
    }

    #[Route('/api/v1/admin/archipelago-client', name: 'api_admin_archipelago_client_update', methods: ['PUT'])]
    public function update(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = json_decode($request->getContent(), true);
        $payload = is_array($payload) ? $payload : [];
        $version = is_string($payload['version'] ?? null) ? $payload['version'] : '';
        $downloadUrl = is_string($payload['downloadUrl'] ?? null) ? $payload['downloadUrl'] : '';

        $result = $this->command->update($version, $downloadUrl);

        if ([] !== $result['errors']) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Le client Archipelago contient des erreurs.', 422, $result['errors']);
        }

        return new JsonResponse(['data' => $this->query->get(), 'meta' => ['message' => 'Client Archipelago mis à jour.']]);
    }
}
