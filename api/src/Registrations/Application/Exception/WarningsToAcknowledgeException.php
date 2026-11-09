<?php

declare(strict_types=1);

namespace App\Registrations\Application\Exception;

use App\Registrations\Application\Support\AdminRegistrationWarning;
use App\Shared\Application\Exception\ApplicationFailure;
use App\Shared\Application\Exception\ApplicationFailureTrait;

/**
 * Story 5.10: an admin action crosses member-side rules the admin has not acknowledged yet. Nothing was written; the
 * admin sees the list and replays the call with `acknowledgeWarnings: true`. Maps to HTTP 409.
 */
final class WarningsToAcknowledgeException extends \RuntimeException implements ApplicationFailure
{
    use ApplicationFailureTrait;

    /**
     * @param non-empty-list<AdminRegistrationWarning> $warnings
     */
    public function __construct(array $warnings)
    {
        $message = 'Cette action dépasse des règles d\'inscription : confirme pour continuer.';
        parent::__construct($message);
        $this->failureMessage = $message;
        $this->failureCode = 'warnings_to_acknowledge';
        $this->failureDetails = [
            'warnings' => array_map(
                static fn (AdminRegistrationWarning $warning): array => ['code' => $warning->value, 'message' => $warning->message()],
                $warnings,
            ),
        ];
    }

    /**
     * Throws when there is anything left to acknowledge.
     *
     * @param list<AdminRegistrationWarning> $warnings
     */
    public static function unlessAcknowledged(array $warnings, bool $acknowledged): void
    {
        if (!$acknowledged && [] !== $warnings) {
            throw new self($warnings);
        }
    }

    public function statusCode(): int
    {
        return 409;
    }
}
