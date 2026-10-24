<?php

declare(strict_types=1);

namespace App\Wallet\Application\Command;

use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\ValidationException;
use App\Wallet\Application\Exception\BlindTestSignatureException;
use App\Wallet\Application\Support\BlindTestGate;
use App\Wallet\Application\Support\BlindTestLink;
use App\Wallet\Domain\Entity\PelleMovement;
use App\Wallet\Domain\Enum\PelleKind;
use App\Wallet\Domain\Enum\PelleReason;
use App\Wallet\Domain\Repository\PelleMovementRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * A source found at an event's blind test, credited in event pelles (story 41.40).
 *
 * The app signs `{timestamp}.{body}`; a stale or wrong signature is refused before anything is read. The amount is
 * the event's setting, never the app's. The key makes a second send of the same find a no-op, and the cap is checked
 * under the member's ledger lock, after the write, so two credits sent together cannot pass it.
 */
final readonly class CreditBlindTestSource
{
    public const int MAX_CLOCK_SKEW_SECONDS = 300;
    public const string KEY_PREFIX = 'blindtest:';
    private const int MAX_KEY_LENGTH = 120;
    private const int MAX_SOURCE_LENGTH = 120;

    public function __construct(
        private BlindTestLink $link,
        private BlindTestGate $gate,
        private RecordPelleMovement $record,
        private PelleMovementRepositoryInterface $movements,
        private ClockInterface $clock,
    ) {
    }

    public function credit(string $timestamp, string $signature, string $body): BlindTestCredit
    {
        $now = $this->clock->now();
        if (!ctype_digit($timestamp)
            || abs($now->getTimestamp() - (int) $timestamp) > self::MAX_CLOCK_SKEW_SECONDS
            || !$this->link->verify($timestamp.'.'.$body, $signature)) {
            throw new BlindTestSignatureException();
        }

        $payload = json_decode($body, true);
        $payload = is_array($payload) ? $payload : [];
        $eventId = is_string($payload['eventId'] ?? null) ? $payload['eventId'] : '';
        $userId = is_string($payload['userId'] ?? null) ? $payload['userId'] : '';
        $key = is_string($payload['uniqueKey'] ?? null) ? $payload['uniqueKey'] : '';
        $source = is_string($payload['source'] ?? null) ? trim($payload['source']) : '';
        if ('' === $eventId || '' === $userId || '' === $source || mb_strlen($source) > self::MAX_SOURCE_LENGTH
            || !str_starts_with($key, self::KEY_PREFIX) || strlen($key) > self::MAX_KEY_LENGTH) {
            throw new ValidationException('Crédit de blind test invalide.', [], 'invalid_blind_test_credit');
        }

        $opening = $this->gate->open($eventId, $userId, $now);
        $cap = $opening->capPerPlayer;
        $recorded = $this->record->record(
            new RecordPelleMovementInput(
                $userId,
                $opening->pellesPerSource,
                PelleKind::Event,
                $eventId,
                PelleReason::BlindTest,
                mb_substr('Blind test : '.$source, 0, PelleMovement::LABEL_MAX_LENGTH),
                null,
                $key,
            ),
            function () use ($userId, $eventId, $cap): void {
                if ($this->movements->totalOfReason($userId, $eventId, PelleReason::BlindTest) > $cap) {
                    throw new ConflictException(sprintf('Plafond du blind test atteint (%d pelles).', $cap), 'cap_reached');
                }
            },
        );

        return new BlindTestCredit(
            $recorded->alreadyRecorded ? 0 : $opening->pellesPerSource,
            $recorded->balanceAfter,
            $recorded->alreadyRecorded,
        );
    }
}
