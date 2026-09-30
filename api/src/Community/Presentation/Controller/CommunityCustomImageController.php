<?php

declare(strict_types=1);

namespace App\Community\Presentation\Controller;

use App\Community\Application\Service\CommunityCustomImageService;
use App\Community\Application\Service\CustomImageUpload;
use App\Community\Domain\Enum\CustomImageRefusal;
use App\Community\Domain\Enum\CustomImageSlot;
use App\Identity\Domain\Entity\User;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Member-uploaded profile images, on the caller's own profile: the avatar (story 30.27) and the banner image
 * (story 30.40). What may go where is decided by the application, from the file's bytes and the caller's status.
 */
final readonly class CommunityCustomImageController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private CommunityCustomImageService $images,
    ) {
    }

    #[Route('/api/v1/community/profile/avatar', name: 'api_community_profile_avatar_upload', methods: ['POST'])]
    public function uploadAvatar(Request $request): JsonResponse
    {
        return $this->upload($request, CustomImageSlot::Avatar, 'avatarUrl');
    }

    #[Route('/api/v1/community/profile/avatar', name: 'api_community_profile_avatar_remove', methods: ['DELETE'])]
    public function removeAvatar(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => ['avatarUrl' => $this->images->removeAvatar($user->getId())]]);
    }

    #[Route('/api/v1/community/profile/banner', name: 'api_community_profile_banner_upload', methods: ['POST'])]
    public function uploadBanner(Request $request): JsonResponse
    {
        return $this->upload($request, CustomImageSlot::Banner, 'bannerImageUrl');
    }

    #[Route('/api/v1/community/profile/banner', name: 'api_community_profile_banner_remove', methods: ['DELETE'])]
    public function removeBanner(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $this->images->removeBanner($user->getId());

        return new JsonResponse(['data' => ['bannerImageUrl' => null]]);
    }

    private function upload(Request $request, CustomImageSlot $slot, string $urlField): JsonResponse
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
                return $this->refused(CustomImageRefusal::TooLarge, $slot);
            }

            return $this->apiAccessGuard->errorResponse('upload_error', 'Le fichier uploadé est invalide.', 422);
        }

        $bytes = file_get_contents($file->getPathname());
        if (false === $bytes) {
            return $this->apiAccessGuard->errorResponse('upload_error', 'Le fichier uploadé est invalide.', 422);
        }

        $result = $this->images->upload($slot, $user->getId(), $this->isAdmin($user), $bytes);

        return $this->respond($result, $slot, $urlField);
    }

    private function respond(CustomImageUpload $result, CustomImageSlot $slot, string $urlField): JsonResponse
    {
        if ($result->storageUnavailable) {
            return $this->apiAccessGuard->errorResponse('storage_unavailable', 'Le stockage est indisponible.', 503);
        }
        if (null !== $result->refusal) {
            return $this->refused($result->refusal, $slot);
        }

        return new JsonResponse(['data' => [$urlField => $result->url]]);
    }

    private function refused(CustomImageRefusal $refusal, CustomImageSlot $slot): JsonResponse
    {
        $limit = CustomImageSlot::Avatar === $slot ? '5 Mo (10 Mo pour un GIF)' : '10 Mo';

        return match ($refusal) {
            CustomImageRefusal::NotAllowed => $this->apiAccessGuard->errorResponse('banner_not_allowed', "L'image de bannière est réservée aux adhérents et aux admins.", 403),
            CustomImageRefusal::GifAdminOnly => $this->apiAccessGuard->errorResponse('image_gif_admin_only', 'Les GIF sont réservés aux admins.', 422),
            CustomImageRefusal::AnimationUnsupported => $this->apiAccessGuard->errorResponse('image_animation_unsupported', "Les images animées ne sont acceptées qu'en GIF.", 422),
            CustomImageRefusal::UnsupportedType => $this->apiAccessGuard->errorResponse('image_invalid_type', 'Type de fichier non supporté. Utilisez JPEG, PNG ou WebP.', 422),
            CustomImageRefusal::TooLarge => $this->apiAccessGuard->errorResponse('image_too_large', sprintf("L'image ne peut pas dépasser %s.", $limit), 422),
        };
    }

    private function isAdmin(User $user): bool
    {
        return in_array('ROLE_ADMIN', $user->getRoles(), true);
    }
}
