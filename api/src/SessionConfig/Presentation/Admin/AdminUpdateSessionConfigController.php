<?php

declare(strict_types=1);

namespace App\SessionConfig\Presentation\Admin;

use App\SessionConfig\Application\AdminUpdateSessionConfig;
use App\SessionConfig\Domain\SessionType;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminUpdateSessionConfigController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminUpdateSessionConfig $updateConfig,
    ) {
    }

    #[Route('/api/v1/admin/session-config/{type}', name: 'api_admin_session_config_update', methods: ['PUT'])]
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

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->apiAccessGuard->errorResponse('invalid_body', 'request body must be a JSON object', Response::HTTP_BAD_REQUEST);
        }

        try {
            /** @var array<string, mixed> $payload */
            $data = $this->updateConfig->execute($sessionType, $payload);
        } catch (\DomainException $e) {
            return $this->apiAccessGuard->errorResponse($e->getMessage(), $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['data' => $data]);
    }
}
