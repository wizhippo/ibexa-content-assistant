<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\Yaml\Yaml;

final class WizhippoIbexaContentAssistantExtension extends Extension implements PrependExtensionInterface
{
    public function prepend(ContainerBuilder $container): void
    {
        foreach (['framework.yaml' => 'framework', 'ai_open_responses.yaml' => 'ai', 'ai.yaml' => 'ai'] as $file => $extension) {
            $path = dirname(__DIR__, 2).'/config/'.$file;
            $container->addResource(new FileResource($path));
            $container->prependExtensionConfig($extension, Yaml::parseFile($path)[$extension]);
        }
    }

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $loader = new YamlFileLoader($container, new FileLocator(dirname(__DIR__, 2).'/config'));
        $loader->load('defaults.yaml');
        $loader->load('services.yaml');
        $container->setParameter('wizhippo_ibexa_content_assistant.enabled', $config['enabled']);
        $container->setParameter('wizhippo_ibexa_content_assistant.fields', $config['fields']);
        if ($config['actions'] !== null) {
            $container->setParameter('wizhippo_ibexa_content_assistant.actions', $config['actions']);
        }
        foreach (['WIZHIPPO_IBEXA_CONTENT_ASSISTANT_MODEL' => 'gpt-4.1-mini', 'OPENAI_BASE_URL' => 'https://api.openai.com/v1', 'OPENAI_API_KEY' => ''] as $name => $value) {
            if (!$container->hasParameter('env('.$name.')')) {
                $container->setParameter('env('.$name.')', $value);
            }
        }
    }
}
