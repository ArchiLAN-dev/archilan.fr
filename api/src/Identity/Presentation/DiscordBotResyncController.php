<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use App\Identity\Application\DiscordResyncAllUsersInterface;
use App\Shared\Infrastructure\Http\ApiAccessGuard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final readonly class DiscordBotResyncController
{
    public function __construct(
        private ApiAccessGuard $apiAccessGuard,
        private DiscordResyncAllUsersInterface $resync,
    ) {
    }

    #[Route('/api/v1/admin/discord-bot/resync', name: 'api_identity_admin_discord_bot_resync', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        $admin = $this->apiAccessGuard->requireAdmin($request);

        if ($admin instanceof JsonResponse) {
            return $admin;
        }

        $queued = $this->resync->run();

        return new JsonResponse(['data' => ['queued' => $queued]], 202);
    }
}
