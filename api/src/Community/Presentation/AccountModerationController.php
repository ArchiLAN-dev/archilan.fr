<?php

declare(strict_types=1);

namespace App\Community\Presentation;

use App\Community\Application\AccountModerationService;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin account moderation endpoints (story 30.29): warn / suspend / ban / lift + action history. Admin-only.
 */
final readonly class AccountModerationController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AccountModerationService $moderation,
    ) {
    }

    #[Route('/api/v1/admin/community/accounts/{userId}/warn', name: 'api_admin_community_account_warn', methods: ['POST'])]
    public function warn(Request $request, string $userId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = $this->jsonPayload($request);

        return $this->respond($this->moderation->warn($admin->getId(), $userId, $this->reason($payload), $this->reportId($payload)));
    }

    #[Route('/api/v1/admin/community/accounts/{userId}/suspend', name: 'api_admin_community_account_suspend', methods: ['POST'])]
    public function suspend(Request $request, string $userId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = $this->jsonPayload($request);
        $until = $this->parseDate(is_string($payload['until'] ?? null) ? $payload['until'] : null);
        if (null === $until) {
            return $this->apiAccessGuard->errorResponse('invalid_date', 'Date de fin de suspension invalide.', 422);
        }

        return $this->respond($this->moderation->suspend($admin->getId(), $userId, $until, $this->reason($payload), $this->reportId($payload)));
    }

    #[Route('/api/v1/admin/community/accounts/{userId}/ban', name: 'api_admin_community_account_ban', methods: ['POST'])]
    public function ban(Request $request, string $userId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $payload = $this->jsonPayload($request);

        return $this->respond($this->moderation->ban($admin->getId(), $userId, $this->reason($payload), $this->reportId($payload)));
    }

    #[Route('/api/v1/admin/community/accounts/{userId}/lift', name: 'api_admin_community_account_lift', methods: ['POST'])]
    public function lift(Request $request, string $userId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return $this->respond($this->moderation->lift($admin->getId(), $userId, $this->reason($this->jsonPayload($request))));
    }

    #[Route('/api/v1/admin/community/accounts/{userId}/actions', name: 'api_admin_community_account_actions', methods: ['GET'])]
    public function actions(Request $request, string $userId): JsonResponse
    {
        $admin = $this->requireAuthenticatedAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->moderation->history($userId)]);
    }

    private function respond(string $result): JsonResponse
    {
        return match ($result) {
            'ok' => new JsonResponse(null, 204),
            'invalid' => $this->apiAccessGuard->errorResponse('invalid_action', 'Action invalide (motif requis, date future).', 422),
            default => $this->apiAccessGuard->errorResponse('not_found', 'Compte introuvable.', 404),
        };
    }

    private function parseDate(?string $value): ?\DateTimeImmutable
    {
        if (null === $value || '' === $value) {
            return null;
        }

        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function reason(array $payload): string
    {
        return is_string($payload['reason'] ?? null) ? $payload['reason'] : '';
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function reportId(array $payload): ?string
    {
        return is_string($payload['reportId'] ?? null) && '' !== $payload['reportId'] ? $payload['reportId'] : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonPayload(Request $request): array
    {
        try {
            $payload = json_decode($request->getContent() ?: '{}', true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($payload)) {
            return [];
        }

        $normalized = [];
        foreach ($payload as $key => $value) {
            if (is_string($key)) {
                $normalized[$key] = $value;
            }
        }

        return $normalized;
    }
}
