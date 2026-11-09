<?php

declare(strict_types=1);

namespace App\Sessions\Application\Support;

use App\Events\Domain\Entity\Event;
use App\PersonalRuns\Domain\Entity\Run;
use App\SessionConfig\Domain\ValueObject\SessionServerConfig;
use App\Sessions\Domain\Entity\Session;
use App\Wallet\Domain\Enum\PelleKind;

/**
 * Story 52.4: the Passeur's rules in one session (see PasseurTermsResolver). An event pays in its silver pelles
 * only, a private run in its bronze.
 */
final readonly class PasseurTerms
{
    /**
     * @param 'event'|'bronze' $kind
     */
    public function __construct(
        public Session $session,
        public ?Run $run,
        public ?Event $event,
        public SessionServerConfig $config,
        public string $kind,
    ) {
    }

    public function enabled(): bool
    {
        return $this->config->passeur;
    }

    public function pelleKind(): PelleKind
    {
        return 'bronze' === $this->kind ? PelleKind::Bronze : PelleKind::Event;
    }

    public function absenceDelaySeconds(): int
    {
        return $this->config->passeurAbsenceMinutes * 60;
    }
}
