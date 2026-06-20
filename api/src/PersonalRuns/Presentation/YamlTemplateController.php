<?php

declare(strict_types=1);

namespace App\PersonalRuns\Presentation;

use App\PersonalRuns\Application\PersonalRunYamlTemplates;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use App\Shared\Presentation\RequiresAuthTrait;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class YamlTemplateController
{
    use RequiresAuthTrait;

    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private PersonalRunYamlTemplates $templates,
    ) {
    }

    #[Route('/api/v1/yaml-templates', name: 'api_yaml_templates_list', methods: ['GET'])]
    public function list(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $gameId = $request->query->get('gameId');
        $gameId = is_string($gameId) ? trim($gameId) : '';

        $data = '' === $gameId ? [] : $this->templates->list($user->getId(), $gameId);

        return new JsonResponse(['data' => $data]);
    }

    #[Route('/api/v1/yaml-templates', name: 'api_yaml_templates_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->templates->save($user->getId(), $this->jsonPayload($request));

        if (null !== $result['errorCode']) {
            return $this->apiAccessGuard->errorResponse($result['errorCode'], 'Template invalide.', 422, $result['errors']);
        }

        return new JsonResponse(['data' => $result['template']], 201);
    }

    #[Route('/api/v1/yaml-templates/{id}', name: 'api_yaml_templates_update', methods: ['PUT'])]
    public function update(Request $request, string $id): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->templates->update($user->getId(), $id, $this->jsonPayload($request));

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Template introuvable.', 404);
        }

        if (null !== $result['errorCode']) {
            return $this->apiAccessGuard->errorResponse($result['errorCode'], 'Template invalide.', 422, $result['errors']);
        }

        return new JsonResponse(['data' => $result['template']]);
    }

    #[Route('/api/v1/yaml-templates/{id}', name: 'api_yaml_templates_delete', methods: ['DELETE'])]
    public function delete(Request $request, string $id): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request);
        if ($user instanceof JsonResponse) {
            return $user;
        }

        $result = $this->templates->delete($user->getId(), $id);

        if (!$result['found']) {
            return $this->apiAccessGuard->errorResponse('not_found', 'Template introuvable.', 404);
        }

        return new JsonResponse(null, 204);
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
