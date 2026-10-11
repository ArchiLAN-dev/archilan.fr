<?php

declare(strict_types=1);

namespace App\PersonalRuns\Presentation\Controller;

use App\PersonalRuns\Application\Command\AnswerRunInvitation;
use App\PersonalRuns\Application\Command\AnswerRunInvitationOutcome;
use App\PersonalRuns\Application\Command\AnswerRunInvitationResult;
use App\PersonalRuns\Application\Command\InviteFriendsToRun;
use App\PersonalRuns\Application\Command\InviteFriendsToRunOutcome;
use App\PersonalRuns\Application\Query\RunInvitationsQuery;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Friends invited by name into a personal run (story 43.1), next to the invite link.
 */
final readonly class RunInvitationController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private InviteFriendsToRun $invite,
        private AnswerRunInvitation $answer,
        private RunInvitationsQuery $invitations,
    ) {
    }

    #[Route('/api/v1/runs/{runId}/invitations', name: 'api_runs_invitations_send', methods: ['POST'])]
    public function send(Request $request, string $runId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->invite->invite($runId, $user->getId(), self::userIds($request));

        return match ($result->outcome) {
            InviteFriendsToRunOutcome::Invited => new JsonResponse(['data' => ['invited' => $result->invited, 'skipped' => $result->skipped]]),
            InviteFriendsToRunOutcome::NotFound => $this->apiAccessGuard->errorResponse('not_found', 'Partie introuvable.', 404),
            InviteFriendsToRunOutcome::Forbidden => $this->apiAccessGuard->errorResponse('forbidden', 'Seul le créateur de la partie invite par nom.', 403),
            InviteFriendsToRunOutcome::RunEnded => $this->apiAccessGuard->errorResponse('run_ended', 'Cette partie est terminée.', 409),
            InviteFriendsToRunOutcome::DailyLimit => $this->apiAccessGuard->errorResponse('daily_limit', sprintf('Pas plus de %d invitations par jour pour une partie.', InviteFriendsToRun::MAX_PER_DAY), 429),
        };
    }

    #[Route('/api/v1/runs/{runId}/invitations', name: 'api_runs_invitations_list', methods: ['GET'])]
    public function list(Request $request, string $runId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $rows = $this->invitations->forRun($runId, $user->getId());
        if (null === $rows) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Partie introuvable.', 404);
        }

        return new JsonResponse(['data' => $rows]);
    }

    #[Route('/api/v1/account/run-invitations', name: 'api_account_run_invitations', methods: ['GET'])]
    public function mine(Request $request): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => $this->invitations->forInvitee($user->getId())]);
    }

    #[Route('/api/v1/run-invitations/{invitationId}/accept', name: 'api_run_invitation_accept', methods: ['POST'])]
    public function accept(Request $request, string $invitationId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return $this->answered($this->answer->accept($invitationId, $user->getId()));
    }

    #[Route('/api/v1/run-invitations/{invitationId}/decline', name: 'api_run_invitation_decline', methods: ['POST'])]
    public function decline(Request $request, string $invitationId): JsonResponse
    {
        $user = $this->apiAccessGuard->requireUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return $this->answered($this->answer->decline($invitationId, $user->getId()));
    }

    private function answered(AnswerRunInvitationResult $result): JsonResponse
    {
        return match ($result->outcome) {
            AnswerRunInvitationOutcome::Joined, AnswerRunInvitationOutcome::Declined => new JsonResponse(['data' => ['runId' => $result->runId]]),
            AnswerRunInvitationOutcome::NotFound => $this->apiAccessGuard->errorResponse('not_found', 'Invitation introuvable.', 404),
            AnswerRunInvitationOutcome::NoLongerOpen => $this->apiAccessGuard->errorResponse('invitation_closed', 'Cette invitation n\'est plus ouverte.', 409),
            AnswerRunInvitationOutcome::RunEnded => $this->apiAccessGuard->errorResponse('run_ended', 'Cette partie est terminée ou n\'existe plus.', 409),
            AnswerRunInvitationOutcome::NoLongerFriends => $this->apiAccessGuard->errorResponse('no_longer_friends', 'Cette invitation n\'est plus valable.', 409),
        };
    }

    /** @return list<string> */
    private static function userIds(Request $request): array
    {
        try {
            $payload = json_decode($request->getContent() ?: '{}', true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        $ids = is_array($payload) ? ($payload['userIds'] ?? null) : null;
        if (!is_array($ids)) {
            return [];
        }

        return array_values(array_filter($ids, static fn (mixed $id): bool => is_string($id) && '' !== $id));
    }
}
