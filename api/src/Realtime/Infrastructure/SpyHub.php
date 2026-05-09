<?php

declare(strict_types=1);

namespace App\Realtime\Infrastructure;

use Symfony\Component\Mercure\HubInterface;
use Symfony\Component\Mercure\Jwt\TokenFactoryInterface;
use Symfony\Component\Mercure\Jwt\TokenProviderInterface;
use Symfony\Component\Mercure\Update;

final class SpyHub implements HubInterface
{
    /** @var list<Update> */
    public static array $published = [];

    public static function reset(): void
    {
        self::$published = [];
    }

    public function getUrl(): string
    {
        return '';
    }

    public function getPublicUrl(): string
    {
        return '';
    }

    public function getProvider(): TokenProviderInterface
    {
        return new class implements TokenProviderInterface {
            public function getJwt(): string
            {
                return '';
            }
        };
    }

    public function getFactory(): TokenFactoryInterface
    {
        return new NullTokenFactory();
    }

    public function publish(Update $update): string
    {
        self::$published[] = $update;

        return '';
    }
}
