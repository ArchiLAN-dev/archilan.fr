<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Shared\Application\Exception\ValidationException;
use App\Wallet\Application\Support\BlindTestGate;
use App\Wallet\Application\Support\BlindTestLink;
use App\Wallet\Domain\Entity\BlindTestLoginCode;
use App\Wallet\Domain\Repository\BlindTestLoginCodeRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * The site's half of the blind test login (story 41.45): for a signed-in member who may play, a one-time code valid a
 * minute, and the app's return address carrying it with the app's own `state`. Only the code's hash is kept.
 */
final readonly class IssueBlindTestLoginCode
{
    public const int TTL_SECONDS = 60;
    private const string STATE = '/^[A-Za-z0-9_-]{16,64}$/';

    public function __construct(
        private BlindTestGate $gate,
        private BlindTestLink $link,
        private BlindTestLoginCodeRepositoryInterface $codes,
        private ClockInterface $clock,
    ) {
    }

    public function issue(string $userId, string $eventId, string $state): BlindTestLoginRedirect
    {
        if (1 !== preg_match(self::STATE, $state)) {
            throw new ValidationException('Connexion au blind test invalide : relance-la depuis l\'app.', [], 'invalid_state');
        }
        $now = $this->clock->now();
        $this->gate->open($eventId, $now);

        $this->codes->removeExpired($now);
        $code = bin2hex(random_bytes(32));
        $this->codes->save(new BlindTestLoginCode(BlindTestLoginCode::hash($code), $userId, $eventId, $state, $now->modify(sprintf('+%d seconds', self::TTL_SECONDS))));

        return new BlindTestLoginRedirect($this->link->callbackUrl($code, $state));
    }
}
