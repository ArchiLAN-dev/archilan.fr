<?php

declare(strict_types=1);

namespace App\Community\Presentation;

use App\Community\Application\CommunityAvatarService;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Member-uploaded avatar endpoints (story 30.27). Self-service: the caller acts on their own profile,
 * gated by `ApiAccessGuard::requireUser`. Validation mirrors the tutorial image upload (story 31.10).
 */
final readonly class CommunityAvatarController
{
    private const array ALLOWED_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    private const int MAX_SIZE_BYTES = 5 * 1024 * 1024;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private CommunityAvatarService $avatars,
    ) {
    }

    #[Route('/api/v1/community/profile/avatar', name: 'api_community_profile_avatar_upload', methods: ['POST'])]
    public function upload(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $file = $request->files->get('file');
        if (!$file instanceof UploadedFile) {
            return $this->apiAccessGuard->errorResponse('missing_file', 'Aucun fichier fourni.', 422);
        }

        if (!$file->isValid()) {
            $uploadError = $file->getError();
            if (\UPLOAD_ERR_INI_SIZE === $uploadError || \UPLOAD_ERR_FORM_SIZE === $uploadError) {
                return $this->apiAccessGuard->errorResponse('image_too_large', "L'image ne peut pas dépasser 5 Mo.", 422);
            }

            return $this->apiAccessGuard->errorResponse('upload_error', 'Le fichier uploadé est invalide.', 422);
        }

        if ($file->getSize() > self::MAX_SIZE_BYTES) {
            return $this->apiAccessGuard->errorResponse('image_too_large', "L'image ne peut pas dépasser 5 Mo.", 422);
        }

        $mime = $file->getMimeType() ?? '';
        if (!array_key_exists($mime, self::ALLOWED_MIMES)) {
            return $this->apiAccessGuard->errorResponse('image_invalid_type', 'Type de fichier non supporté. Utilisez JPEG, PNG ou WebP.', 422);
        }

        $key = sprintf('community/avatars/%s.%s', bin2hex(random_bytes(16)), self::ALLOWED_MIMES[$mime]);
        $contents = (string) file_get_contents((string) $file->getRealPath());

        try {
            $url = $this->avatars->upload($user->getId(), $key, $contents);
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('storage_unavailable', 'Le stockage est indisponible.', 503);
        }

        return new JsonResponse(['data' => ['avatarUrl' => $url]]);
    }

    #[Route('/api/v1/community/profile/avatar', name: 'api_community_profile_avatar_remove', methods: ['DELETE'])]
    public function remove(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $url = $this->avatars->remove($user->getId());

        return new JsonResponse(['data' => ['avatarUrl' => $url]]);
    }
}
