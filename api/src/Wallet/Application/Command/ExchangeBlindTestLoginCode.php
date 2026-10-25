<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Wallet\Application\Exception\BlindTestSignatureException;
use App\Wallet\Application\Support\BlindTestGate;
use App\Wallet\Application\Support\BlindTestLink;
use App\Wallet\Domain\Entity\BlindTestLoginCode;
use App\Wallet\Domain\Repository\BlindTestLoginCodeRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * The app's half of the blind test login (story 41.45): a signed request trading the one-time code for who the member
 * is. The code is deleted on first use, served or not; the access rules are checked again, so a member unregistered in
 * the meantime gets nothing.
 */
final readonly class ExchangeBlindTestLoginCode
{
    /** An animator's session lasts a long evening, then they log in again. */
    public const int ANIMATION_SECONDS = 12 * 3600;

    public function __construct(
        private BlindTestLink $link,
        private BlindTestGate $gate,
        private BlindTestLoginCodeRepositoryInterface $codes,
        private UserRepositoryInterface $users,
        private ClockInterface $clock,
    ) {
    }

    public function exchange(string $timestamp, string $signature, string $body): BlindTestIdentity
    {
        $now = $this->clock->now();
        if (!$this->link->verifyRequest($timestamp, $signature, $body, $now)) {
            throw new BlindTestSignatureException();
        }
        $payload = json_decode($body, true);
        $code = is_array($payload) && is_string($payload['code'] ?? null) ? $payload['code'] : '';
        $found = '' === $code ? null : $this->codes->find(BlindTestLoginCode::hash($code));
        if (!$found instanceof BlindTestLoginCode) {
            throw new NotFoundException('Code de connexion inconnu ou déjà utilisé.', 'login_code_invalid');
        }
        $this->codes->remove($found);
        if ($found->isExpired($now)) {
            throw new NotFoundException('Code de connexion expiré.', 'login_code_invalid');
        }

        $member = $this->users->findById($found->getUserId());
        if (!$member instanceof User) {
            throw new NotFoundException('Membre introuvable.', 'member_not_found');
        }
        $eventId = $found->getEventId();
        if (null === $eventId) {
            // Story 41.53: an animation code, for an admin still an admin now.
            if ($member->isDeleted() || !in_array('ROLE_ADMIN', $member->getRoles(), true)) {
                throw new ForbiddenException('Réservé aux admins d\'archilan.fr.', 'not_admin');
            }

            return new BlindTestIdentity($member->getId(), null, $member->getDisplayName(), $found->getState(), $now->getTimestamp() + self::ANIMATION_SECONDS, BlindTestIdentity::ANIMATOR);
        }
        $opening = $this->gate->open($eventId, $member->getId(), $now);

        return new BlindTestIdentity($member->getId(), $eventId, $member->getDisplayName(), $found->getState(), $opening->endsAt->getTimestamp());
    }
}
