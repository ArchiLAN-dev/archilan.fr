<?php

declare(strict_types=1);

use Archilan\BridgeClientBundle\Bridge\BridgeClientFactory;
use Archilan\BridgeClientBundle\Bridge\BridgeClientPool;
use Archilan\BridgeClientBundle\Command\BridgeListenCommand;
use Archilan\BridgeClientBundle\Ws\WsDispatcherFactory;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    // Shared factory — token from config, HTTP client from Symfony's autowiring
    $services->set(BridgeClientFactory::class)
        ->args([
            '%archi_bridge.admin_token%',
            service(HttpClientInterface::class),
        ]);

    // Request-scoped pool — one BridgeClient per session, cached by session ID
    $services->set(BridgeClientPool::class)
        ->args([service(BridgeClientFactory::class)]);

    // WS dispatcher factory — subscribers auto-injected via tagged_iterator
    $services->set(WsDispatcherFactory::class)
        ->args([
            service(BridgeClientFactory::class),
            tagged_iterator('archi_bridge.ws_subscriber'),
        ]);

    // Built-in listen command
    $services->set(BridgeListenCommand::class)
        ->args([service(WsDispatcherFactory::class)])
        ->tag('console.command');
};
