<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Application\DiscordUsersQueryInterface;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DiscordBotUsersController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private DiscordUsersQueryInterface $query,
    ) {
    }

    #[Route('/api/v1/admin/discord-bot/users', name: 'api_identity_admin_discord_bot_users', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $page = (int) $request->query->get('page', '1');
        $limit = (int) $request->query->get('limit', '50');

        $result = $this->query->query(max(1, $page), max(1, min(200, $limit)));

        return new JsonResponse($result);
    }
}
