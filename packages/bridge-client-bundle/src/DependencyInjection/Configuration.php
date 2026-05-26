<?php

declare(strict_types=1);

namespace Archilan\BridgeClientBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('archi_bridge');

        $tree->getRootNode()
            ->children()
                ->scalarNode('admin_token')
                    ->isRequired()
                    ->cannotBeEmpty()
                    ->info('Bearer token shared by all bridge instances (BRIDGE_TOKEN env var).')
                ->end()
            ->end();

        return $tree;
    }
}
