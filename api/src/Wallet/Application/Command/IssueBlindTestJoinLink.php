<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Wallet\Application\Support\BlindTestGate;
use App\Wallet\Application\Support\BlindTestLink;
use Psr\Clock\ClockInterface;

/**
 * A member's way into the blind test app (story 41.40): a link carrying who they are, signed by the site, valid until
 * the end of the event. The app checks the signature and reads nothing else of the site.
 */
final readonly class IssueBlindTestJoinLink
{
    public function __construct(
        private BlindTestGate $gate,
        private BlindTestLink $link,
        private UserRepositoryInterface $users,
        private ClockInterface $clock,
    ) {
    }

    public function issue(string $userId, string $eventId): BlindTestJoinLink
    {
        $opening = $this->gate->open($eventId, $userId, $this->clock->now());
        $member = $this->users->findById($userId);
        if (!$member instanceof User) {
            throw new NotFoundException('Membre introuvable.', 'member_not_found');
        }

        return new BlindTestJoinLink($this->link->joinUrl([
            'userId' => $userId,
            'eventId' => $eventId,
            'displayName' => $member->getDisplayName(),
            'exp' => $opening->endsAt->getTimestamp(),
        ]));
    }
}
