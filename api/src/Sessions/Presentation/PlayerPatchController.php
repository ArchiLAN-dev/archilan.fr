<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\PlayerSessionConnection;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final readonly class PlayerPatchController
{
    private const ALLOWED_STATUSES = ['running', 'stopped', 'finished'];

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private PlayerSessionConnection $playerSessionConnection,
        private string $workspaceDir,
    ) {
    }

    #[Route('/api/v1/registrations/{registrationId}/patches', methods: ['GET'])]
    public function list(Request $request, string $registrationId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $connection = $this->playerSessionConnection->getConnection($registrationId, $user->getId());
        if (null === $connection) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Inscription introuvable.', 404);
        }

        $session = $connection['session'] ?? null;
        if (null === $session || !\in_array($session['status'] ?? '', self::ALLOWED_STATUSES, true)) {
            return new JsonResponse(['data' => ['files' => []]]);
        }

        $slotNames = array_map(
            static fn (array $s): string => $s['slotName'],
            $connection['slots'],
        );

        $sessionId = $session['id'];
        if (!is_string($sessionId)) {
            return new JsonResponse(['data' => ['files' => []]]);
        }
        $files = $this->findPatchFiles($sessionId, $slotNames);

        return new JsonResponse(['data' => ['files' => $files]]);
    }

    #[Route('/api/v1/registrations/{registrationId}/patches/{filename}', methods: ['GET'])]
    public function download(Request $request, string $registrationId, string $filename): Response
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $connection = $this->playerSessionConnection->getConnection($registrationId, $user->getId());
        if (null === $connection) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Inscription introuvable.', 404);
        }

        $session = $connection['session'] ?? null;
        if (null === $session) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Aucune session active.', 404);
        }

        if (!\in_array($session['status'] ?? '', self::ALLOWED_STATUSES, true)) {
            return $this->apiAccessGuard->errorResponse('forbidden', "La session n'est pas encore lancée.", 403);
        }

        // Reject .archipelago seed files
        $ext = strtolower(pathinfo($filename, \PATHINFO_EXTENSION));
        if ('archipelago' === $ext) {
            return $this->apiAccessGuard->errorResponse('forbidden', 'Fichier non autorisé.', 403);
        }

        // Verify the file stem matches one of the player's slots
        $stem = pathinfo($filename, \PATHINFO_FILENAME);
        $slotNames = array_map(
            static fn (array $s): string => $s['slotName'],
            $connection['slots'],
        );

        if (!\in_array($stem, $slotNames, true)) {
            return $this->apiAccessGuard->errorResponse('forbidden', "Ce fichier n'appartient pas à votre inscription.", 403);
        }

        // Resolve path and prevent traversal
        $sessionId = $session['id'];
        if (!is_string($sessionId)) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Fichier introuvable.', 404);
        }
        $outputDir = realpath($this->workspaceDir.'/'.$sessionId.'/output');
        if (false === $outputDir) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Fichier introuvable.', 404);
        }

        $filePath = realpath($outputDir.'/'.$filename);
        if (false === $filePath || !str_starts_with($filePath, $outputDir.\DIRECTORY_SEPARATOR)) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Fichier introuvable.', 404);
        }

        if (!is_file($filePath)) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Fichier introuvable.', 404);
        }

        $response = new BinaryFileResponse($filePath);
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);

        return $response;
    }

    /**
     * @param list<string> $slotNames
     *
     * @return list<string>
     */
    private function findPatchFiles(string $sessionId, array $slotNames): array
    {
        $outputDir = $this->workspaceDir.'/'.$sessionId.'/output';
        if (!is_dir($outputDir)) {
            return [];
        }

        $files = [];
        foreach (glob($outputDir.'/*') ?: [] as $path) {
            if (!is_file($path)) {
                continue;
            }
            if ('archipelago' === strtolower(pathinfo($path, \PATHINFO_EXTENSION))) {
                continue;
            }
            $stem = pathinfo($path, \PATHINFO_FILENAME);
            if (\in_array($stem, $slotNames, true)) {
                $files[] = basename($path);
            }
        }

        sort($files);

        return $files;
    }
}
