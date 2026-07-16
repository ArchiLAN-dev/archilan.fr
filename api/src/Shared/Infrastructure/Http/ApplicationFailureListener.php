<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Application\Exception\ApplicationFailure;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Turns any thrown {@see ApplicationFailure} into a JSON response, so command services can signal failure
 * by throwing a typed exception instead of returning an outcome-array discriminant that each controller has
 * to branch on (epic 35, Stage 1).
 *
 * Runs at the default priority (0), above Symfony's framework ErrorListener (-128): it sets the response
 * first, and non-ApplicationFailure throwables are left untouched for the default handling.
 */
#[AsEventListener(event: KernelEvents::EXCEPTION)]
final readonly class ApplicationFailureListener
{
    public function __invoke(ExceptionEvent $event): void
    {
        $failure = $event->getThrowable();
        if (!$failure instanceof ApplicationFailure) {
            return;
        }

        // `details` is always present (as []) to match ApiAccessGuard::errorResponse, the dominant error
        // envelope - so converting an errorResponse call site to a thrown failure is byte-identical.
        $error = [
            'code' => $failure->errorCode(),
            'message' => $failure->clientMessage(),
            'details' => $failure->details(),
        ];

        $event->setResponse(new JsonResponse(['error' => $error], $failure->statusCode()));
    }
}
