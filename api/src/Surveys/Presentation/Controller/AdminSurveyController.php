<?php

declare(strict_types=1);

namespace App\Surveys\Presentation\Controller;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use App\Surveys\Application\Command\ManageSurveys;
use App\Surveys\Application\Command\RemindSurveyNonRespondents;
use App\Surveys\Application\Command\SurveyDraft;
use App\Surveys\Application\Command\SurveyReminderOutcome;
use App\Surveys\Application\Query\AdminSurveyQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 51.1: the surveys of an event, as the admins write them. Admins only, on every route. Invalid input from the
 * Application layer comes as typed failures, mapped to HTTP by the epic-35 listener.
 */
final readonly class AdminSurveyController
{
    use RequiresAuthTrait;

    private const array STRING_FIELDS = ['title', 'intro', 'audience', 'opensAt', 'closesAt'];
    private const array BOOL_FIELDS = ['anonymous', 'sendEmail'];

    public function __construct(
        private ManageSurveys $manage,
        private RemindSurveyNonRespondents $remind,
        private AdminSurveyQuery $query,
        private ApiAccessGuard $apiAccessGuard,
    ) {
    }

    #[Route('/api/v1/admin/events/{eventId}/surveys', name: 'api_admin_surveys', methods: ['GET'])]
    public function list(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->query->forEvent($eventId), 'meta' => []]);
    }

    #[Route('/api/v1/admin/events/{eventId}/surveys', name: 'api_admin_surveys_create', methods: ['POST'])]
    public function create(Request $request, string $eventId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $draft = $this->draft($this->payload($request));
        if (null === $draft) {
            return $this->invalidJson();
        }

        return new JsonResponse(['data' => ['id' => $this->manage->create($eventId, $draft)], 'meta' => []], 201);
    }

    #[Route('/api/v1/admin/surveys/{surveyId}', name: 'api_admin_survey', methods: ['GET'])]
    public function show(Request $request, string $surveyId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->query->survey($surveyId), 'meta' => []]);
    }

    #[Route('/api/v1/admin/surveys/{surveyId}', name: 'api_admin_survey_revise', methods: ['PATCH'])]
    public function revise(Request $request, string $surveyId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $draft = $this->draft($this->payload($request));
        if (null === $draft) {
            return $this->invalidJson();
        }

        $this->manage->revise($surveyId, $draft);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/surveys/{surveyId}', name: 'api_admin_survey_delete', methods: ['DELETE'])]
    public function delete(Request $request, string $surveyId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->delete($surveyId);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/surveys/{surveyId}/publish', name: 'api_admin_survey_publish', methods: ['POST'])]
    public function publish(Request $request, string $surveyId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->publish($surveyId);

        return new JsonResponse(null, 204);
    }

    /** Story 51.3: the members who have not answered are reminded, at most once every 24 hours. */
    #[Route('/api/v1/admin/surveys/{surveyId}/remind', name: 'api_admin_survey_remind', methods: ['POST'])]
    public function remind(Request $request, string $surveyId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $result = ($this->remind)($surveyId);
        $remindedAt = $result->remindedAt?->format(\DateTimeInterface::ATOM);
        if (SurveyReminderOutcome::TooSoon === $result->outcome) {
            return new JsonResponse([
                'error' => ['code' => 'survey_remind_too_soon', 'message' => 'Une relance est déjà partie dans les dernières 24 h.', 'details' => []],
                'data' => ['lastRemindedAt' => $remindedAt],
            ], 429);
        }

        return new JsonResponse(['data' => ['recipients' => $result->recipients, 'lastRemindedAt' => $remindedAt], 'meta' => []]);
    }

    #[Route('/api/v1/admin/surveys/{surveyId}/questions', name: 'api_admin_survey_question_add', methods: ['POST'])]
    public function addQuestion(Request $request, string $surveyId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $payload = $this->payload($request);
        $kind = $payload['kind'] ?? null;
        $label = $payload['label'] ?? null;
        $required = $payload['required'] ?? false;
        $options = self::strings($payload['options'] ?? []);
        if (!is_string($kind) || !is_string($label) || !is_bool($required) || null === $options) {
            return $this->invalidJson();
        }

        $id = $this->manage->addQuestion($surveyId, $kind, $label, $required, $options);

        return new JsonResponse(['data' => ['id' => $id], 'meta' => []], 201);
    }

    #[Route('/api/v1/admin/surveys/{surveyId}/questions/order', name: 'api_admin_survey_questions_order', methods: ['PUT'])]
    public function orderQuestions(Request $request, string $surveyId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $ids = self::strings($this->payload($request)['ids'] ?? null);
        if (null === $ids) {
            return $this->invalidJson();
        }

        $this->manage->orderQuestions($surveyId, $ids);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/surveys/{surveyId}/questions/{questionId}', name: 'api_admin_survey_question_revise', methods: ['PATCH'])]
    public function reviseQuestion(Request $request, string $surveyId, string $questionId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $payload = $this->payload($request);
        $label = $payload['label'] ?? null;
        $required = $payload['required'] ?? null;
        $kind = $payload['kind'] ?? null;
        if ((null !== $label && !is_string($label)) || (null !== $required && !is_bool($required)) || (null !== $kind && !is_string($kind))) {
            return $this->invalidJson();
        }

        $this->manage->reviseQuestion($surveyId, $questionId, $label, $required, $kind);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/surveys/{surveyId}/questions/{questionId}', name: 'api_admin_survey_question_delete', methods: ['DELETE'])]
    public function deleteQuestion(Request $request, string $surveyId, string $questionId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->deleteQuestion($surveyId, $questionId);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/surveys/{surveyId}/questions/{questionId}/options', name: 'api_admin_survey_option_add', methods: ['POST'])]
    public function addOption(Request $request, string $surveyId, string $questionId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $label = $this->payload($request)['label'] ?? null;
        if (!is_string($label)) {
            return $this->invalidJson();
        }

        $id = $this->manage->addOption($surveyId, $questionId, $label);

        return new JsonResponse(['data' => ['id' => $id], 'meta' => []], 201);
    }

    #[Route('/api/v1/admin/surveys/{surveyId}/questions/{questionId}/options/order', name: 'api_admin_survey_options_order', methods: ['PUT'])]
    public function orderOptions(Request $request, string $surveyId, string $questionId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $ids = self::strings($this->payload($request)['ids'] ?? null);
        if (null === $ids) {
            return $this->invalidJson();
        }

        $this->manage->orderOptions($surveyId, $questionId, $ids);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/surveys/{surveyId}/options/{optionId}', name: 'api_admin_survey_option_rename', methods: ['PATCH'])]
    public function renameOption(Request $request, string $surveyId, string $optionId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }
        $label = $this->payload($request)['label'] ?? null;
        if (!is_string($label)) {
            return $this->invalidJson();
        }

        $this->manage->renameOption($surveyId, $optionId, $label);

        return new JsonResponse(null, 204);
    }

    #[Route('/api/v1/admin/surveys/{surveyId}/options/{optionId}', name: 'api_admin_survey_option_remove', methods: ['DELETE'])]
    public function removeOption(Request $request, string $surveyId, string $optionId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $this->manage->removeOption($surveyId, $optionId);

        return new JsonResponse(null, 204);
    }

    /**
     * The survey fields of a body, or null when one has the wrong type. A missing or null field is left out.
     *
     * @param array<mixed> $payload
     */
    private function draft(array $payload): ?SurveyDraft
    {
        foreach (self::STRING_FIELDS as $field) {
            if (null !== ($payload[$field] ?? null) && !is_string($payload[$field])) {
                return null;
            }
        }
        foreach (self::BOOL_FIELDS as $field) {
            if (null !== ($payload[$field] ?? null) && !is_bool($payload[$field])) {
                return null;
            }
        }
        $string = static fn (string $field): ?string => is_string($payload[$field] ?? null) ? $payload[$field] : null;
        $bool = static fn (string $field): ?bool => is_bool($payload[$field] ?? null) ? $payload[$field] : null;

        return new SurveyDraft(
            $string('title'),
            $string('intro'),
            $string('audience'),
            $bool('anonymous'),
            $bool('sendEmail'),
            $string('opensAt'),
            $string('closesAt'),
        );
    }

    /** @return list<string>|null */
    private static function strings(mixed $value): ?array
    {
        if (!is_array($value) || !array_is_list($value)) {
            return null;
        }
        $strings = array_values(array_filter($value, is_string(...)));

        return count($strings) === count($value) ? $strings : null;
    }

    /** @return array<mixed> */
    private function payload(Request $request): array
    {
        $payload = json_decode($request->getContent(), true);

        return is_array($payload) ? $payload : [];
    }

    private function invalidJson(): JsonResponse
    {
        return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
    }
}
