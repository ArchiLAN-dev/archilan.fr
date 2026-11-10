<?php

declare(strict_types=1);

namespace App\Surveys\Presentation\Controller;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use App\Surveys\Application\Command\AnswerSurvey;
use App\Surveys\Application\Command\SubmittedAnswer;
use App\Surveys\Application\Query\MemberSurveyQuery;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 51.2: the surveys a member is asked, and the member's answers. A member reads only their own answers, and
 * never those of an anonymous survey.
 */
final readonly class MemberSurveyController
{
    use RequiresAuthTrait;

    public function __construct(
        private MemberSurveyQuery $query,
        private AnswerSurvey $answer,
        private ApiAccessGuard $apiAccessGuard,
    ) {
    }

    #[Route('/api/v1/events/{eventId}/my-surveys', name: 'api_event_my_surveys', methods: ['GET'])]
    public function mine(Request $request, string $eventId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => $this->query->forEvent($eventId, $user->getId()), 'meta' => []]);
    }

    #[Route('/api/v1/surveys/{surveyId}/my-response', name: 'api_survey_my_response', methods: ['GET'])]
    public function form(Request $request, string $surveyId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => $this->query->form($surveyId, $user->getId()), 'meta' => []]);
    }

    #[Route('/api/v1/surveys/{surveyId}/my-response', name: 'api_survey_answer', methods: ['PUT'])]
    public function answer(Request $request, string $surveyId): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }
        $answers = self::answers(json_decode($request->getContent(), true));
        if (null === $answers) {
            return $this->apiAccessGuard->errorResponse('invalid_json', 'Corps de requête invalide.', 400);
        }

        $this->answer->answer($surveyId, $user->getId(), $answers);

        return new JsonResponse(null, 204);
    }

    /**
     * `{answers: [{questionId, optionIds?, text?, rating?}]}`, or null when a field has the wrong type.
     *
     * @return list<SubmittedAnswer>|null
     */
    private static function answers(mixed $payload): ?array
    {
        $items = is_array($payload) ? ($payload['answers'] ?? null) : null;
        if (!is_array($items) || !array_is_list($items)) {
            return null;
        }
        $answers = [];
        foreach ($items as $item) {
            if (!is_array($item) || !is_string($item['questionId'] ?? null)) {
                return null;
            }
            $optionIds = $item['optionIds'] ?? [];
            $text = $item['text'] ?? null;
            $rating = $item['rating'] ?? null;
            if (!is_array($optionIds) || !array_is_list($optionIds) || array_filter($optionIds, is_string(...)) !== $optionIds
                || (null !== $text && !is_string($text)) || (null !== $rating && !is_int($rating))) {
                return null;
            }
            $answers[] = new SubmittedAnswer($item['questionId'], $optionIds, $text, $rating);
        }

        return $answers;
    }
}
