<?php

declare(strict_types=1);

namespace App\Wallet\Presentation\Controller;

use App\Wallet\Application\Query\CommunityQuestGauge;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Story 41.69: the gauge of the week's collective quest, public - it names no member.
 */
final readonly class CommunityQuestController
{
    public function __construct(private CommunityQuestGauge $gauge)
    {
    }

    #[Route('/api/v1/community/collective-quest', name: 'api_wallet_community_collective_quest', methods: ['GET'])]
    public function current(): JsonResponse
    {
        return new JsonResponse(['quest' => $this->gauge->current()]);
    }
}
