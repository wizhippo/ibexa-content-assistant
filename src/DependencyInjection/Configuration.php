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
            ->integerNode('context_window')->min(1)->defaultValue('%env(int:WIZHIPPO_IBEXA_CONTENT_ASSISTANT_CONTEXT_WINDOW)%')->end()
            ->integerNode('max_output_tokens')->min(1)->defaultValue(128000)->end()
            ->integerNode('token_safety_margin')->min(0)->defaultValue(1024)->end()
            ->integerNode('max_request_bytes')->min(1)->defaultValue(8388608)->end()
            ->integerNode('max_xml_bytes')->min(1)->defaultValue(4194304)->end()
            ->integerNode('max_response_bytes')->min(1)->defaultValue(4194304)->end()
            ->end();

        return $tree;
    }
}
