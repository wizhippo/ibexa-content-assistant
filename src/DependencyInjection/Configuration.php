<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

final class Configuration implements ConfigurationInterface
{
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $tree = new TreeBuilder('wizhippo_ibexa_content_assistant');
        $tree->getRootNode()->children()
            ->scalarNode('enabled')->defaultFalse()->end()
            ->arrayNode('fields')->useAttributeAsKey('content_type')->arrayPrototype()->scalarPrototype()->end()->end()->end()
            ->variableNode('actions')->defaultNull()->end()
            ->end();

        return $tree;
    }
}
