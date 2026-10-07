<?php

declare(strict_types=1);

namespace App\Community\Presentation\Controller;

use App\Community\Application\Command\ManageAvatarFrames;
use App\Community\Application\Query\AvatarFrameCatalogQuery;
use App\Community\Application\Support\AvatarFrameFileRule;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The video frames (story 41.10): the public catalog the avatars read, and its admin.
 */
final readonly class AvatarFrameController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AvatarFrameCatalogQuery $query,
        private ManageAvatarFrames $manage,
    ) {
    }

    #[Route('/api/v1/avatar-frames', name: 'api_community_avatar_frames', methods: ['GET'])]
    public function published(): JsonResponse
    {
        $response = new JsonResponse(['frames' => $this->query->published()]);
        // Read by every page that shows an avatar: a few minutes of cache, a new frame shows up soon enough.
        $response->setPublic();
        $response->setMaxAge(300);

        return $response;
    }

    #[Route('/api/v1/admin/avatar-frames', name: 'api_community_admin_avatar_frames', methods: ['GET'])]
    public function admin(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['frames' => $this->query->forAdmin()]);
    }

    #[Route('/api/v1/admin/avatar-frames', name: 'api_community_admin_avatar_frames_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $files = $this->files($request, [...AvatarFrameFileRule::ROLES, ...AvatarFrameFileRule::SHADE_ROLES]);

        $uploaded = $this->manage->upload(
            $request->request->getString('key'),
            $request->request->getString('label'),
            $request->request->getString('access'),
            $files,
        );

        return new JsonResponse(['key' => $uploaded->key], 201);
    }

    #[Route('/api/v1/admin/avatar-frames/{key}', name: 'api_community_admin_avatar_frames_update', methods: ['PATCH'])]
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

    /** Story 41.30: gives an uploaded frame its shade, or replaces it (multipart: shadeWebm, shadeMp4). */
    #[Route('/api/v1/admin/avatar-frames/{key}/shade', name: 'api_community_admin_avatar_frames_shade', methods: ['POST'])]
    public function shade(Request $request, string $key): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->shade($key, $this->files($request, AvatarFrameFileRule::SHADE_ROLES));

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/avatar-frames/{key}/shade', name: 'api_community_admin_avatar_frames_shade_remove', methods: ['DELETE'])]
    public function removeShade(Request $request, string $key): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->removeShade($key);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/avatar-frames/{key}/retire', name: 'api_community_admin_avatar_frames_retire', methods: ['POST'])]
    public function retire(Request $request, string $key): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->retire($key);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/avatar-frames/{key}/restore', name: 'api_community_admin_avatar_frames_restore', methods: ['POST'])]
    public function restore(Request $request, string $key): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->restore($key);

        return new JsonResponse(null, 204);
    }

    /**
     * The bytes of the multipart files of these roles.
     *
     * @param list<string> $roles
     *
     * @return array<string, string>
     */
    private function files(Request $request, array $roles): array
    {
        $files = [];
        foreach ($roles as $role) {
            $file = $request->files->get($role);
            if ($file instanceof UploadedFile && $file->isValid()) {
                $bytes = file_get_contents($file->getPathname());
                $files[$role] = false === $bytes ? '' : $bytes;
            }
        }

        return $files;
    }
}
