<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Presentation\Admin;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Infrastructure\MinioStorageInterface;
use App\WeeklyRuns\Application\AdminWeeklyRunOutputQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminWeeklyRunOutputDownloadController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminWeeklyRunOutputQuery $outputQuery,
        private MinioStorageInterface $minioStorage,
        private string $minioSessionsBucket,
    ) {
    }

    #[Route('/api/v1/admin/weekly-runs/{weeklyRunId}/output', name: 'api_admin_weekly_run_output_download', methods: ['GET'])]
    public function __invoke(Request $request, string $weeklyRunId): Response
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $outputKey = $this->outputQuery->findOutputKey($weeklyRunId);
        if (null === $outputKey) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Seed non disponible pour ce run.', 404);
        }

        try {
            $contents = $this->minioStorage->download($this->minioSessionsBucket, $outputKey);
        } catch (\Throwable) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Seed introuvable dans le stockage.', 404);
        }

        // New artifacts are a full-output zip; give it a readable name. Legacy single-file
        // artifacts keep their original basename.
        $filename = str_ends_with($outputKey, '.zip')
            ? 'weekly-run-'.$weeklyRunId.'.zip'
            : basename($outputKey);

        $response = new StreamedResponse(static function () use ($contents): void {
            echo $contents;
        });
        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Disposition', 'attachment; filename="'.$filename.'"');

        return $response;
    }
}
