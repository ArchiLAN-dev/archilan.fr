<?php

declare(strict_types=1);

namespace App\Community\Presentation\Controller;

use App\Community\Application\Command\ManageProfileBanners;
use App\Community\Application\Query\ProfileBannerCatalogQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The profile banners (story 41.11): the public catalog the profiles read, and its admin.
 */
final readonly class ProfileBannerController
{
    use RequiresAuthTrait;

    private const array FILE_ROLES = ['image', 'webm', 'mp4'];

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private ProfileBannerCatalogQuery $query,
        private ManageProfileBanners $manage,
    ) {
    }

    #[Route('/api/v1/profile-banners', name: 'api_community_profile_banners', methods: ['GET'])]
    public function published(): JsonResponse
    {
        $response = new JsonResponse(['banners' => $this->query->published()]);
        // A few minutes of cache, a new banner shows up soon enough.
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }

    #[Route('/api/v1/admin/profile-banners', name: 'api_community_admin_profile_banners', methods: ['GET'])]
    public function admin(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['banners' => $this->query->forAdmin()]);
    }

    #[Route('/api/v1/admin/profile-banners', name: 'api_community_admin_profile_banners_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $files = [];
        foreach (self::FILE_ROLES as $role) {
            $file = $request->files->get($role);
            if ($file instanceof UploadedFile && $file->isValid()) {
                $bytes = file_get_contents($file->getPathname());
                $files[$role] = false === $bytes ? '' : $bytes;
            }
        }

        $uploaded = $this->manage->upload(
            $request->request->getString('key'),
            $request->request->getString('label'),
            $request->request->getString('access'),
            $files,
        );

        return new JsonResponse(['key' => $uploaded->key], 201);
    }

    #[Route('/api/v1/admin/profile-banners/{key}', name: 'api_community_admin_profile_banners_update', methods: ['PATCH'])]
    public function update(Request $request, string $key): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
        }
        $position = $payload['position'] ?? null;

        $this->manage->update(
            $key,
            is_string($payload['label'] ?? null) ? $payload['label'] : null,
            is_string($payload['access'] ?? null) ? $payload['access'] : null,
            is_int($position) ? $position : null,
        );

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/profile-banners/{key}/retire', name: 'api_community_admin_profile_banners_retire', methods: ['POST'])]
    public function retire(Request $request, string $key): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->retire($key);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/profile-banners/{key}/restore', name: 'api_community_admin_profile_banners_restore', methods: ['POST'])]
    public function restore(Request $request, string $key): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->restore($key);

        return new JsonResponse(null, 204);
    }
}
