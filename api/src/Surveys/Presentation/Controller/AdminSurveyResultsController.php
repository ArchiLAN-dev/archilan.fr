<?php

declare(strict_types=1);

namespace App\Surveys\Presentation\Controller;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Surveys\Application\Query\SurveyResultsQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 51.4: what the members answered. Admins only. Read at each call: the screens always show the current state.
 */
final readonly class AdminSurveyResultsController
{
    public function __construct(
        private SurveyResultsQuery $query,
        private ApiAccessGuard $apiAccessGuard,
    ) {
    }

    #[Route('/api/v1/admin/surveys/{surveyId}/results', name: 'api_admin_survey_results', methods: ['GET'])]
    public function results(Request $request, string $surveyId): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->query->results($surveyId), 'meta' => []]);
    }

    #[Route('/api/v1/admin/surveys/{surveyId}/respondents', name: 'api_admin_survey_respondents', methods: ['GET'])]
    public function respondents(Request $request, string $surveyId): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->query->respondents($surveyId), 'meta' => []]);
    }

    #[Route('/api/v1/admin/surveys/{surveyId}/non-respondents', name: 'api_admin_survey_non_respondents', methods: ['GET'])]
    public function nonRespondents(Request $request, string $surveyId): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->query->nonRespondents($surveyId), 'meta' => []]);
    }

    #[Route('/api/v1/admin/events/{eventId}/registrations/{registrationId}/surveys', name: 'api_admin_registration_surveys', methods: ['GET'])]
    public function forRegistration(Request $request, string $eventId, string $registrationId): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->query->forRegistration($eventId, $registrationId), 'meta' => []]);
    }
}
