<?php

declare(strict_types=1);

namespace App\Realtime\Infrastructure;

use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;

final class NullTokenFactory implements TokenFactoryInterface
{
    public function create(?array $subscribe = [], ?array $publish = [], array $additionalClaims = []): string
    {
        return 'null-token';
    }
}
