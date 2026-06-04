<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Application\DiscordBotStatusQueryInterface;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DiscordBotStatusController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private DiscordBotStatusQueryInterface $query,
    ) {
    }

    #[Route('/api/v1/admin/discord-bot/status', name: 'api_identity_admin_discord_bot_status', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        return new JsonResponse(['data' => $this->query->query()]);
    }
}
