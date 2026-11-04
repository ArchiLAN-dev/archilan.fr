<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Story 50.3: the headers every API response carries. HSTS is ignored by browsers on plain HTTP, so it needs no
 * condition on the scheme. PHP adds X-Powered-By on its own, outside the Response object.
 */
#[AsEventListener(event: KernelEvents::RESPONSE)]
final readonly class SecurityHeadersListener
{
    private const string HSTS = 'max-age=63072000; includeSubDomains';

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $headers = $event->getResponse()->headers;
        $headers->set('X-Content-Type-Options', 'nosniff');
        $headers->set('Strict-Transport-Security', self::HSTS);
        $headers->remove('X-Powered-By');
        header_remove('X-Powered-By');
    }
}
