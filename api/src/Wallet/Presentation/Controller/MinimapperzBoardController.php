<?php

declare(strict_types=1);

namespace App\Wallet\Presentation\Controller;

use App\Wallet\Application\Query\MinimapperzBoard;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/** Story 49.4: the Minimapperz board of an event, public, for the OBS overlay and the LAN screen. */
final readonly class MinimapperzBoardController
{
    public function __construct(private MinimapperzBoard $board)
    {
    }

    #[Route('/api/v1/events/{eventId}/minimapperz/board', name: 'api_wallet_minimapperz_board', methods: ['GET'])]
    public function __invoke(string $eventId): JsonResponse
    {
        return new JsonResponse(['data' => $this->board->of($eventId)]);
    }
}
