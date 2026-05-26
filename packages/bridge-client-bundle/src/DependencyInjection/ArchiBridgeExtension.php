<?php

declare(strict_types=1);

namespace Archilan\BridgeClientBundle\DependencyInjection;

use Archilan\BridgeClient\Ws\Listener\WsSubscriber;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;

final class ArchiBridgeExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('archi_bridge.admin_token', $config['admin_token']);

        // Any service implementing WsSubscriber (or a sub-interface: FeedListener,
        // StateChangedListener, …) is automatically tagged and injected into
        // WsDispatcherFactory — no manual wiring needed.
        $container->registerForAutoconfiguration(WsSubscriber::class)
            ->addTag('archi_bridge.ws_subscriber');

        $loader = new PhpFileLoader($container, new FileLocator(dirname(__DIR__, 2).'/config'));
        $loader->load('services.php');
    }
}
