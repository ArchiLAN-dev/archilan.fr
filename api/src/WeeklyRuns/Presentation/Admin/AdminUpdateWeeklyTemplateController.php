<?php

declare(strict_types=1);

namespace App\WeeklyRuns\Presentation\Admin;

use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\WeeklyRuns\Application\AdminUpdateWeeklyTemplate;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final readonly class AdminUpdateWeeklyTemplateController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private AdminUpdateWeeklyTemplate $updateWeeklyTemplate,
    ) {
    }

    #[Route('/api/v1/admin/weekly-templates/{id}', name: 'api_admin_weekly_templates_update', methods: ['PATCH'])]
    public function __invoke(Request $request, string $id): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $body = $this->jsonPayload($request);

        /** @var array{name?: string|null, yamlConfig?: string, maxAttempts?: int|null, isActive?: bool} $changes */
        $changes = [];

        if (array_key_exists('name', $body)) {
            $rawName = $body['name'];
            $changes['name'] = is_string($rawName) && '' !== trim($rawName) ? trim($rawName) : null;
        }

        if (array_key_exists('yamlConfig', $body) && is_string($body['yamlConfig'])) {
            $changes['yamlConfig'] = $body['yamlConfig'];
        }

        if (array_key_exists('maxAttempts', $body)) {
            $rawMax = $body['maxAttempts'];
            $changes['maxAttempts'] = is_int($rawMax) && $rawMax > 0 ? $rawMax : null;
        }

        if (array_key_exists('isActive', $body) && is_bool($body['isActive'])) {
            $changes['isActive'] = $body['isActive'];
        }

        $data = $this->updateWeeklyTemplate->execute($id, $changes);

        if (null === $data) {
            return new JsonResponse(['error' => 'not_found'], Response::HTTP_NOT_FOUND);
        }

        return new JsonResponse(['data' => $data]);
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
