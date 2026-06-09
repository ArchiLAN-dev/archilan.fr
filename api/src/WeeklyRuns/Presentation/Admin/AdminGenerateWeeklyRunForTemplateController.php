<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Presentation\Admin;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\WeeklyRuns\Application\GenerateWeeklyRunForTemplate;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminGenerateWeeklyRunForTemplateController
{
    private const ERROR_STATUS = [
        'template_not_found' => Response::HTTP_NOT_FOUND,
        'run_already_exists' => Response::HTTP_CONFLICT,
        'template_incomplete' => Response::HTTP_UNPROCESSABLE_ENTITY,
    ];

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private GenerateWeeklyRunForTemplate $generateForTemplate,
    ) {
    }

    #[Route('/api/v1/admin/weekly-templates/{templateId}/generate', name: 'api_admin_weekly_template_generate', methods: ['POST'])]
    public function __invoke(Request $request, string $templateId): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        try {
            $this->generateForTemplate->generate($templateId);
        } catch (\DomainException $e) {
            $code = $e->getMessage();
            $status = self::ERROR_STATUS[$code] ?? Response::HTTP_BAD_REQUEST;

            return $this->apiAccessGuard->errorResponse($code, $code, $status);
        } catch (\Throwable $e) {
            return $this->apiAccessGuard->errorResponse('generation_failed', $e->getMessage(), 500);
        }

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
