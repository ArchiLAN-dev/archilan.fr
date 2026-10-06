<?php

declare(strict_types=1);

namespace App\Community\Presentation\Controller;

use App\Community\Application\Command\ManageProfileTitles;
use App\Community\Application\Query\ProfileTitleCatalogQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The profile titles (story 41.22): the public catalog the profiles read, and its admin.
 */
final readonly class ProfileTitleController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private ProfileTitleCatalogQuery $query,
        private ManageProfileTitles $manage,
    ) {
    }

    #[Route('/api/v1/profile-titles', name: 'api_community_profile_titles', methods: ['GET'])]
    public function published(): JsonResponse
    {
        $response = new JsonResponse(['titles' => $this->query->published()]);
        // A few minutes of cache, a new title shows up soon enough.
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }

    #[Route('/api/v1/admin/profile-titles', name: 'api_community_admin_profile_titles', methods: ['GET'])]
    public function admin(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['titles' => $this->query->forAdmin()]);
    }

    #[Route('/api/v1/admin/profile-titles', name: 'api_community_admin_profile_titles_write', methods: ['POST'])]
    public function write(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $payload = $this->payload($request);

        $this->manage->write($this->text($payload, 'key') ?? '', $this->text($payload, 'label') ?? '', $this->text($payload, 'access') ?? '');

        return new JsonResponse(null, 201);
    }

    #[Route('/api/v1/admin/profile-titles/{key}', name: 'api_community_admin_profile_titles_update', methods: ['PATCH'])]
    public function update(Request $request, string $key): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $payload = $this->payload($request);
        $position = $payload['position'] ?? null;

        $this->manage->update($key, $this->text($payload, 'label'), $this->text($payload, 'access'), is_int($position) ? $position : null);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/profile-titles/{key}/retire', name: 'api_community_admin_profile_titles_retire', methods: ['POST'])]
    public function retire(Request $request, string $key): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->retire($key);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/profile-titles/{key}/restore', name: 'api_community_admin_profile_titles_restore', methods: ['POST'])]
    public function restore(Request $request, string $key): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->restore($key);

        return new JsonResponse(null, 204);
    }

    /** @return array<mixed> */
    private function payload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);

        return is_array($payload) ? $payload : [];
    }

    /** @param array<mixed> $payload */
    private function text(array $payload, string $key): ?string
    {
        return is_string($payload[$key] ?? null) ? $payload[$key] : null;
    }
}
