<?php

declare(strict_types=1);

namespace App\Shared\Application\Exception;

/**
 * A failure the Application layer can raise to signal an outcome that maps to an HTTP response,
 * without the Application knowing anything about HTTP. The central kernel listener
 * (Shared\Infrastructure\Http\ApplicationFailureListener) turns any thrown ApplicationFailure into a
 * { error: { code, message, details? } } JSON response with the given status.
 *
 * Epic 35 Stage 1: replaces the outcome-array discriminants returned by command services.
 */
interface ApplicationFailure extends \Throwable
{
    public function statusCode(): int;

    public function errorCode(): string;

    public function clientMessage(): string;

    /**
     * Field-level details (e.g. a validation field map), or an empty array when there are none.
     *
     * @return array<string, mixed>
     */
    public function details(): array;
}
