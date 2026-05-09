<?php

declare(strict_types=1);

namespace App\Sessions\Presentation;

use App\Sessions\Application\SessionOrchestrator;
use App\Sessions\Domain\Session;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;

final readonly class SessionOrchestrationController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private SessionOrchestrator $sessionOrchestrator,
        private EntityManagerInterface $entityManager,
        private string $workspaceDir,
    ) {
    }

    #[Route('/api/v1/admin/sessions/{sessionId}/validate', methods: ['POST'])]
    public function validate(Request $request, string $sessionId): JsonResponse
    {
        $guard = $this->apiAccessGuard->requireAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $result = $this->sessionOrchestrator->orchestrateValidate($sessionId);

        if (!($result['found'] ?? false)) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        $rawErrors = $result['errors'] ?? null;
        if (is_array($rawErrors)) {
            $errors = $this->toStringList($rawErrors);

            return $this->apiAccessGuard->errorResponse('invalid_state', implode(' ', $errors), 409);
        }

        return new JsonResponse(['data' => $result['session']]);
    }

    #[Route('/api/v1/admin/sessions/{sessionId}/generate', methods: ['POST'])]
    public function generate(Request $request, string $sessionId): JsonResponse
    {
        $guard = $this->apiAccessGuard->requireAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $result = $this->sessionOrchestrator->orchestrateGenerate($sessionId);

        if (!($result['found'] ?? false)) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        $rawErrors = $result['errors'] ?? null;
        if (is_array($rawErrors)) {
            $errors = $this->toStringList($rawErrors);
            if (in_array('runner_unavailable', $errors, true)) {
                return $this->apiAccessGuard->errorResponse('runner_unavailable', 'Le runner est indisponible.', 503);
            }

            return $this->apiAccessGuard->errorResponse('invalid_transition', implode(' ', $errors), 409);
        }

        return new JsonResponse(['data' => $result['session']]);
    }

    #[Route('/api/v1/admin/sessions/{sessionId}/launch', methods: ['POST'])]
    public function launch(Request $request, string $sessionId): JsonResponse
    {
        $guard = $this->apiAccessGuard->requireAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $result = $this->sessionOrchestrator->orchestrateLaunch($sessionId);

        if (!($result['found'] ?? false)) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        $rawErrors = $result['errors'] ?? null;
        if (is_array($rawErrors)) {
            $errors = $this->toStringList($rawErrors);
            if (in_array('runner_unavailable', $errors, true)) {
                return $this->apiAccessGuard->errorResponse('runner_unavailable', 'Le runner est indisponible.', 503);
            }

            return $this->apiAccessGuard->errorResponse('invalid_transition', implode(' ', $errors), 409);
        }

        return new JsonResponse(['data' => $result['session']]);
    }

    #[Route('/api/v1/admin/sessions/{sessionId}/force-launch', methods: ['POST'])]
    public function forceLaunch(Request $request, string $sessionId): JsonResponse
    {
        $guard = $this->apiAccessGuard->requireAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $result = $this->sessionOrchestrator->orchestrateForceLaunch($sessionId);

        if (!($result['found'] ?? false)) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        $rawErrors = $result['errors'] ?? null;
        if (is_array($rawErrors)) {
            $errors = $this->toStringList($rawErrors);

            return $this->apiAccessGuard->errorResponse('invalid_transition', implode(' ', $errors), 409);
        }

        return new JsonResponse(['data' => $result['session']]);
    }

    #[Route('/api/v1/admin/sessions/{sessionId}/stop', methods: ['POST'])]
    public function stop(Request $request, string $sessionId): JsonResponse
    {
        $guard = $this->apiAccessGuard->requireAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $result = $this->sessionOrchestrator->orchestrateStop($sessionId);

        if (!($result['found'] ?? false)) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        $rawErrors = $result['errors'] ?? null;
        if (is_array($rawErrors)) {
            $errors = $this->toStringList($rawErrors);

            return $this->apiAccessGuard->errorResponse('invalid_transition', implode(' ', $errors), 409);
        }

        return new JsonResponse(['data' => $result['session']]);
    }

    #[Route('/api/v1/admin/sessions/{sessionId}/restart', methods: ['POST'])]
    public function restart(Request $request, string $sessionId): JsonResponse
    {
        $guard = $this->apiAccessGuard->requireAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $result = $this->sessionOrchestrator->orchestrateRestart($sessionId);

        if (!($result['found'] ?? false)) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Session introuvable.', 404);
        }

        $rawErrors = $result['errors'] ?? null;
        if (is_array($rawErrors)) {
            $errors = $this->toStringList($rawErrors);
            if (in_array('runner_unavailable', $errors, true)) {
                return $this->apiAccessGuard->errorResponse('runner_unavailable', 'Le runner est indisponible.', 503);
            }

            return $this->apiAccessGuard->errorResponse('invalid_transition', implode(' ', $errors), 409);
        }

        return new JsonResponse(['data' => $result['session']]);
    }

    #[Route('/api/v1/admin/sessions/{sessionId}/generation.zip', methods: ['GET'])]
    public function downloadGeneration(Request $request, string $sessionId): Response
    {
        $guard = $this->apiAccessGuard->requireAdmin($request);
        if ($guard instanceof JsonResponse) {
            return $guard;
        }

        $sessionDir = $this->workspaceDir.'/'.$sessionId;

        if (!is_dir($sessionDir)) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Aucun fichier de génération trouvé.', 404);
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'generation_');
        if (false === $tmpFile) {
            return $this->apiAccessGuard->errorResponse('server_error', 'Impossible de créer le fichier temporaire.', 500);
        }

        $zip = new \ZipArchive();
        $zip->open($tmpFile, \ZipArchive::OVERWRITE);

        $added = 0;
        foreach (['yamls', 'output', 'apworlds', 'saves', 'seed'] as $subdir) {
            $dir = $sessionDir.'/'.$subdir;
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                $relativePath = $subdir.'/'.ltrim(substr($file->getPathname(), \strlen($dir)), '/\\');
                $zip->addFile($file->getPathname(), $relativePath);
                ++$added;
            }
        }

        // Si la save a été archivée vers un emplacement permanent, l'inclure aussi.
        $session = $this->entityManager->find(Session::class, $sessionId);
        $archivedSavePath = $session instanceof Session ? $session->getArchivedSavePath() : null;
        if (is_string($archivedSavePath) && is_file($archivedSavePath)) {
            $zip->addFile($archivedSavePath, 'saves/'.basename($archivedSavePath));
            ++$added;
        }

        $zip->close();

        if (0 === $added) {
            unlink($tmpFile);

            return $this->apiAccessGuard->errorResponse('not_found', 'Aucun fichier de génération trouvé.', 404);
        }

        $response = new BinaryFileResponse($tmpFile);
        $response->headers->set('Content-Type', 'application/zip');
        $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $sessionId.'-generation.zip');
        $response->deleteFileAfterSend(true);

        return $response;
    }

    /**
     * @param array<mixed> $list
     *
     * @return list<string>
     */
    private function toStringList(array $list): array
    {
        $result = [];
        foreach ($list as $item) {
            if (is_string($item)) {
                $result[] = $item;
            }
        }

        return $result;
    }
}
