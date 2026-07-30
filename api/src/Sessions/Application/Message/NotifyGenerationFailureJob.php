<?php

declare(strict_types=1);

namespace App\Sessions\Application\Message;

/**
 * Dispatched after a generation crash is recorded (story 9.41) to notify the players whose
 * slots broke the seed, plus the run owner. Findings are captured at crash time (from
 * GenerationFailureParser) so the notification content survives later session mutations.
 */
final readonly class NotifyGenerationFailureJob
{
    public const string NOTIFICATION_TYPE = 'generation_failed';

    /**
     * @param list<array{slotName: string|null, message: string}> $findings
     */
    public function __construct(
        public string $sessionId,
        public array $findings,
    ) {
    }
}
