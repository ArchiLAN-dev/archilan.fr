<?php

declare(strict_types=1);

namespace App\Community\Presentation\Controller;

use App\Community\Application\Command\ContactModeration;
use App\Community\Application\Command\ContactModerationOutcome;
use App\Community\Application\Query\MemberModerationContactQuery;
use App\Identity\Application\Support\ModerationContactPass;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\Support\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A sanctioned member writes to the moderation (story 39.2). Two doors onto the same exchange: the member's
 * account while they can log in, and the contact pass a blocked login hands out, which opens nothing else.
 */
final readonly class ModerationContactController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private ContactModeration $contactModeration,
        private MemberModerationContactQuery $contact,
        private ModerationContactPass $pass,
    ) {
    }

    #[Route('/api/v1/account/moderation-contact', name: 'api_account_moderation_contact', methods: ['GET'])]
    public function accountThread(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return new JsonResponse(['data' => $this->contact->forMember($user->getId())]);
    }

    #[Route('/api/v1/account/moderation-contact', name: 'api_account_moderation_contact_write', methods: ['POST'])]
    public function accountWrite(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        return $this->respond($this->contactModeration->write($user->getId(), $this->body($request)));
    }

    #[Route(ModerationContactPass::COOKIE_PATH, name: 'api_moderation_contact_blocked', methods: ['GET'])]
    public function blockedThread(Request $request): JsonResponse
    {
        $memberId = $this->blockedMember($request);
        $thread = null !== $memberId ? $this->contact->forBlockedMember($memberId) : null;
        if (null === $thread) {
            return $this->noPass();
        }

        return new JsonResponse(['data' => $thread]);
    }

    #[Route(ModerationContactPass::COOKIE_PATH, name: 'api_moderation_contact_blocked_write', methods: ['POST'])]
    public function blockedWrite(Request $request): JsonResponse
    {
        $memberId = $this->blockedMember($request);
        // Still blocked: a member whose sanction ended logs in and writes from their account.
        if (null === $memberId || null === $this->contact->forBlockedMember($memberId)) {
            return $this->noPass();
        }

        return $this->respond($this->contactModeration->write($memberId, $this->body($request)));
    }

    private function blockedMember(Request $request): ?string
    {
        $value = $request->cookies->get(ModerationContactPass::COOKIE_NAME);

        return is_string($value) && '' !== $value ? $this->pass->verify($value) : null;
    }

    private function noPass(): JsonResponse
    {
        return $this->apiAccessGuard->errorResponse('moderation_contact_expired', 'Reconnectez-vous pour écrire à la modération.', 401);
    }

    private function respond(ContactModerationOutcome $outcome): JsonResponse
    {
        return match ($outcome) {
            ContactModerationOutcome::Sent => new JsonResponse(['data' => ['sent' => true]], 201),
            ContactModerationOutcome::Invalid => $this->apiAccessGuard->errorResponse('invalid_message', 'Le message doit faire entre 1 et 2000 caractères.', 422),
            ContactModerationOutcome::NotSanctioned => $this->apiAccessGuard->errorResponse('not_sanctioned', 'Aucune sanction à contester sur ce compte.', 403),
            ContactModerationOutcome::TooMany => $this->apiAccessGuard->errorResponse('too_many_messages', 'Trop de messages en une heure, réessayez plus tard.', 429),
        };
    }

    private function body(Request $request): string
    {
        try {
            $payload = json_decode($request->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return '';
        }

        return is_array($payload) && is_string($payload['body'] ?? null) ? $payload['body'] : '';
    }
}
