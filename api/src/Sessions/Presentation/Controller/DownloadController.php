<?php

declare(strict_types=1);

namespace App\Sessions\Presentation\Controller;

use App\Sessions\Application\Query\ArchivedFile;
use App\Sessions\Application\Query\SessionArchiveDownloads;
use App\Sessions\Domain\Exception\SessionNotFoundException;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DownloadController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionArchiveDownloads $downloads,
    ) {
    }

    #[Route('/api/v1/admin/sessions/{id}/download/spoiler', methods: ['GET'])]
    public function spoiler(Request $request, string $id): Response
    {
        $guard = $this->requireAuthenticatedAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        try {
            $file = $this->downloads->spoiler($id);
        } catch (SessionNotFoundException) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        return null === $file
            ? $this->apiAccessGuard->errorResponse('not_found', 'Spoiler log non disponible.', 404)
            : self::attachment($file, 'text/plain; charset=utf-8');
    }

    #[Route('/api/v1/admin/sessions/{id}/download/save', methods: ['GET'])]
    public function save(Request $request, string $id): Response
    {
        $guard = $this->requireAuthenticatedAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        try {
            $file = $this->downloads->save($id);
        } catch (SessionNotFoundException) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        return null === $file
            ? $this->apiAccessGuard->errorResponse('not_found', 'Sauvegarde non disponible.', 404)
            : self::attachment($file, 'application/octet-stream');
    }

    private static function attachment(ArchivedFile $file, string $contentType): Response
    {
        return new Response($file->contents, 200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => HeaderUtils::makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $file->filename, 'download'),
        ]);
    }
}
