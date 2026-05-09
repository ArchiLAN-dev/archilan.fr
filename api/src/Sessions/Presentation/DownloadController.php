<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Domain\Session;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DownloadController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private EntityManagerInterface $entityManager,
    ) {
    }

    #[Route('/api/v1/admin/sessions/{id}/download/spoiler', methods: ['GET'])]
    public function spoiler(Request $request, string $id): BinaryFileResponse|JsonResponse
    {
        $guard = $this->apiAccessGuard->requireAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $session = $this->entityManager->find(Session::class, $id);
        if (!$session instanceof Session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        $path = $session->getArchivedSpoilerPath();
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
        $guard = $this->apiAccessGuard->requireAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $session = $this->entityManager->find(Session::class, $id);
        if (!$session instanceof Session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        $path = $session->getArchivedSavePath();
        if (null === $path || !file_exists($path)) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Sauvegarde non disponible.', 404);
        }

        $response = new BinaryFileResponse($path);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, basename($path));

        return $response;
    }
}
