<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Shared\Application\Exception\ValidationException;
use App\Wallet\Application\Support\BlindTestLink;
use App\Wallet\Domain\Entity\BlindTestLoginCode;
use App\Wallet\Domain\Repository\BlindTestLoginCodeRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * The site's half of the animators' login (story 41.53): for a signed-in admin (the controller checks the role), a
 * one-time code valid a minute, tied to no event, and the app's return address carrying it with the app's `state`.
 */
final readonly class IssueBlindTestAnimationCode
{
    private const string STATE = '/^[A-Za-z0-9_-]{16,64}$/';

    public function __construct(
        private BlindTestLink $link,
        private BlindTestLoginCodeRepositoryInterface $codes,
        private ClockInterface $clock,
    ) {
    }

    public function issue(string $adminId, string $state): BlindTestLoginRedirect
    {
        if (1 !== preg_match(self::STATE, $state)) {
            throw new ValidationException('Connexion à l\'animation invalide : relance-la depuis l\'app.', [], 'invalid_state');
        }
        $now = $this->clock->now();
        $this->codes->removeExpired($now);
        $code = bin2hex(random_bytes(32));
        $this->codes->save(new BlindTestLoginCode(BlindTestLoginCode::hash($code), $adminId, null, $state, $now->modify(sprintf('+%d seconds', IssueBlindTestLoginCode::TTL_SECONDS))));

        return new BlindTestLoginRedirect($this->link->animationCallbackUrl($code, $state));
    }
}
