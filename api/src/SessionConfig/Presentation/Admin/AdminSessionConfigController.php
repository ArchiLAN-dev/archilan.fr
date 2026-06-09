<?php

declare(strict_types=1);

namespace App\SessionConfig\Presentation\Admin;

use App\SessionConfig\Application\AdminSessionConfigQuery;
use App\SessionConfig\Domain\SessionType;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminSessionConfigController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminSessionConfigQuery $query,
    ) {
    }

    #[Route('/api/v1/admin/session-config/{type}', name: 'api_admin_session_config_get', methods: ['GET'])]
    public function __invoke(Request $request, string $type): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);
        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $sessionType = SessionType::tryFrom($type);
        if (null === $sessionType) {
            return $this->apiAccessGuard->errorResponse('unknown_type', 'unknown session type', Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['data' => $this->query->execute($sessionType)]);
    }
}
