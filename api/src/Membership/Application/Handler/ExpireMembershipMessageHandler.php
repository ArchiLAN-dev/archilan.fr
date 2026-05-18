<?php

declare(strict_types=1);

namespace App\Membership\Application\Handler;

use App\Membership\Application\ExpireMembershipInterface;
use App\Membership\Application\Message\ExpireMembershipMessage;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class ExpireMembershipMessageHandler
{
    public function __construct(
        private ExpireMembershipInterface $expireMembership,
    ) {
    }

    public function __invoke(ExpireMembershipMessage $message): void
    {
        $this->expireMembership->expire($message->membershipId);
    }
}
