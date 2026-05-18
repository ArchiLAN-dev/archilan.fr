<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Presentation\Admin;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\WeeklyRuns\Application\Message\GenerateWeeklyRunsMessage;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminGenerateWeeklyRunsController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private MessageBusInterface $messageBus,
    ) {
    }

    #[Route('/api/v1/admin/weekly-runs/generate', name: 'api_admin_weekly_runs_generate', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->messageBus->dispatch(new GenerateWeeklyRunsMessage());

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
