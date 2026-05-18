<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Presentation\Admin;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\WeeklyRuns\Application\AdminCreateWeeklyTemplate;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminCreateWeeklyTemplateController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminCreateWeeklyTemplate $createWeeklyTemplate,
    ) {
    }

    #[Route('/api/v1/admin/weekly-templates', name: 'api_admin_weekly_templates_create', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $body = $this->jsonPayload($request);

        $gameId = isset($body['gameId']) && is_string($body['gameId']) ? trim($body['gameId']) : null;
        $yamlConfig = isset($body['yamlConfig']) && is_string($body['yamlConfig']) ? $body['yamlConfig'] : null;

        if (null === $gameId || '' === $gameId || null === $yamlConfig || '' === $yamlConfig) {
            return new JsonResponse(['error' => 'missing_required_fields'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $name = isset($body['name']) && is_string($body['name']) && '' !== trim($body['name'])
            ? trim($body['name'])
            : null;

        $maxAttempts = isset($body['maxAttempts']) && is_int($body['maxAttempts']) && $body['maxAttempts'] > 0
            ? $body['maxAttempts']
            : null;

        try {
            $data = $this->createWeeklyTemplate->execute($gameId, $yamlConfig, $name, $maxAttempts);
        } catch (\DomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return new JsonResponse(['data' => $data], Response::HTTP_CREATED);
    }

    /** @return array<string, mixed> */
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
