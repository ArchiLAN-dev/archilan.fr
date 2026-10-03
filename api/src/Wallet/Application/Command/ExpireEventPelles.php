<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Shared\Application\Exception\ForbiddenException;
use App\Shared\Application\Exception\NotFoundException;
use App\Wallet\Application\Query\EventPellesQueryInterface;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;

/**
 * The end of an event for its pelles (story 41.2, decision 3 of epic 41): a tenth of what each member still
 * holds becomes gold pelles, then the whole event balance is destroyed. Both lines are keyed by event and
 * member, and the conversion comes first: a run interrupted between the two converts nothing twice and still
 * destroys the balance on its next pass.
 */
final readonly class ExpireEventPelles
{
    public const int CONVERSION_PERCENT = 10;

    public function __construct(
        private RecordPelleMovement $record,
        private EventPellesQueryInterface $query,
        private ClockInterface $clock,
        private LoggerInterface $logger,
    ) {
    }

    public function expireEnded(): ExpiredEventPelles
    {
        $members = 0;
        $converted = 0;
        $destroyed = 0;

        foreach ($this->query->endedEventBalances($this->clock->now()) as $holding) {
            ['eventId' => $eventId, 'eventTitle' => $title, 'userId' => $userId, 'balance' => $balance] = $holding;
            $label = sprintf('Fin de « %s »', $title);

            $gold = intdiv($balance * self::CONVERSION_PERCENT, 100);
            if ($gold > 0) {
                try {
                    // Not as an admin: a banned member earns nothing, the conversion included.
                    $recorded = $this->record->record(new RecordPelleMovementInput(
                        $userId, $gold, PelleKind::Gold, null, PelleReason::EventConversion, $label, null,
                        sprintf('event-conversion:%s:%s', $eventId, $userId),
                    ));
                    $converted += $recorded->alreadyRecorded ? 0 : $gold;
                } catch (ForbiddenException) {
                } catch (NotFoundException) {
                    continue;
                }
            }

            try {
                // The destruction is the system's doing, so it reaches a banned member too.
                $this->record->record(new RecordPelleMovementInput(
                    $userId, -$balance, PelleKind::Event, $eventId, PelleReason::EventExpired, $label, null,
                    sprintf('event-expired:%s:%s', $eventId, $userId),
                    byAdmin: true,
                ));
            } catch (NotFoundException $e) {
                $this->logger->info('wallet.event_pelles.expire_skipped', ['eventId' => $eventId, 'userId' => $userId, 'reason' => $e->getMessage()]);
                continue;
            }
            ++$members;
            $destroyed += $balance;
        }

        return new ExpiredEventPelles($members, $converted, $destroyed);
    }
}
