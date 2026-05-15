<?php

declare(strict_types=1);

namespace App\CatalogSync\Presentation;

use App\CatalogSync\Application\CatalogSyncStatusQuery;
use App\CatalogSync\Application\IgnoreCatalogEntryCommand;
use App\CatalogSync\Application\UnignoreCatalogEntryCommand;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class CatalogSyncController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private CatalogSyncStatusQuery $catalogSyncStatusQuery,
        private IgnoreCatalogEntryCommand $ignoreCatalogEntryCommand,
        private UnignoreCatalogEntryCommand $unignoreCatalogEntryCommand,
    ) {
    }

    #[Route('/api/v1/admin/catalog-sync', name: 'api_catalog_sync', methods: ['GET'])]
    public function catalogSync(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $force = $request->query->getBoolean('force');

        $data = $this->catalogSyncStatusQuery->fetch($force);

        if (null === $data) {
            return $this->apiAccessGuard->errorResponse(
                'sheet_unavailable',
                'Le catalogue Google Sheets est injoignable.',
                503,
            );
        }

        return new JsonResponse($data);
    }

    #[Route('/api/v1/admin/catalog-sync/ignored', name: 'api_catalog_sync_ignore', methods: ['POST'])]
    public function ignore(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        /** @var array<string, mixed> $body */
        $body = json_decode((string) $request->getContent(), true) ?? [];
        $name = is_string($body['name'] ?? null) ? trim($body['name']) : '';

        if ('' === $name) {
            return $this->apiAccessGuard->errorResponse('validation_failed', 'Le nom est requis.', 422);
        }

        $this->ignoreCatalogEntryCommand->execute($name);

        return new JsonResponse(['name' => $name], 201);
    }

    #[Route('/api/v1/admin/catalog-sync/ignored/{name}', name: 'api_catalog_sync_unignore', methods: ['DELETE'])]
    public function unignore(Request $request, string $name): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $found = $this->unignoreCatalogEntryCommand->execute($name);

        if (!$found) {
            return new JsonResponse(null, 404);
        }

        return new JsonResponse(null, 204);
    }
}
