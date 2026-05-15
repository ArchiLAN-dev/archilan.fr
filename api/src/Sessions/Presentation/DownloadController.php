<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\SessionQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DownloadController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionQuery $sessionQuery,
    ) {
    }

    #[Route('/api/v1/admin/sessions/{id}/download/spoiler', methods: ['GET'])]
    public function spoiler(Request $request, string $id): BinaryFileResponse|JsonResponse
    {
        $guard = $this->requireAuthenticatedAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $session = $this->sessionQuery->findById($id);
        if (null === $session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        $path = $session['archivedSpoilerPath'];
        if (null === $path || !file_exists($path)) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Spoiler log non disponible.', 404);
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($path));

        return $response;
    }

    #[Route('/api/v1/admin/sessions/{id}/download/save', methods: ['GET'])]
    public function save(Request $request, string $id): BinaryFileResponse|JsonResponse
    {
        $guard = $this->requireAuthenticatedAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $session = $this->sessionQuery->findById($id);
        if (null === $session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        $path = $session['archivedSavePath'];
        if (null === $path || !file_exists($path)) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Sauvegarde non disponible.', 404);
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($path));

        return $response;
    }
}
