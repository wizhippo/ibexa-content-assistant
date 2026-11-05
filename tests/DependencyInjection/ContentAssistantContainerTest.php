<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Tests\DependencyInjection;

use PHPUnit\Framework\TestCase;
use Symfony\AI\AiBundle\AiBundle;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpKernel\Kernel;
use Wizhippo\IbexaContentAssistant\DependencyInjection\WizhippoIbexaContentAssistantExtension;
use Wizhippo\IbexaContentAssistant\Exception\ContextWindowExceeded;
use Wizhippo\IbexaContentAssistant\Service\ContentAssistantBudgetProcessor;
use Wizhippo\IbexaContentAssistant\Service\ContentAssistantService;

final class ContentAssistantContainerTest extends TestCase
{
    public function testCompiledAgentUsesConfiguredBudgetsAndIncludesSystemPrompt(): void
    {
        // A minimal kernel tests the real AI extension and compiler passes without
        // requiring an Ibexa database, application credentials, or a provider.
        $kernel = new class('test', false) extends Kernel {
            public function registerBundles(): iterable
            {
                yield new FrameworkBundle();
                yield new AiBundle();
            }

            public function getCacheDir(): string
            {
                return sys_get_temp_dir().'/content-assistant-container-test-'.getmypid();
            }

            public function registerContainerConfiguration(LoaderInterface $loader): void
            {
                $loader->load(static function (ContainerBuilder $container): void {
                    $container->setParameter('env(OPENAI_API_KEY)', 'test-key');
                    $container->loadFromExtension('framework', ['secret' => 'test', 'test' => true, 'property_info' => ['with_constructor_extractor' => true]]);
                    $container->loadFromExtension('wizhippo_ibexa_content_assistant', [
                        'context_window' => 10000, 'max_output_tokens' => 200, 'token_safety_margin' => 100,
                    ]);
                });
            }

            protected function build(ContainerBuilder $container): void
            {
                parent::build($container);
                $container->registerExtension(new WizhippoIbexaContentAssistantExtension());
                $container->addCompilerPass(new class implements CompilerPassInterface {
                    public function process(ContainerBuilder $container): void
                    {
                        $container->removeDefinition('ai.security.is_granted_attribute_listener');
                        foreach ($container->getDefinitions() as $id => $definition) {
                            if (str_starts_with($id, 'Wizhippo\\IbexaContentAssistant\\')
                                && !in_array($id, [ContentAssistantService::class, ContentAssistantBudgetProcessor::class], true)) {
                                $container->removeDefinition($id);
                            }
                        }
                        $container->getDefinition(ContentAssistantService::class)->setPublic(true);
                    }
                }, PassConfig::TYPE_BEFORE_OPTIMIZATION, 1000);
            }
        };
        try {
            $kernel->boot();
            $container = $kernel->getContainer()->get('test.service_container');
            $responseCount = 0;
            $client = new MockHttpClient(static function (string $method, string $url, array $options) use (&$responseCount): MockResponse {
                ++$responseCount;
                $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
                self::assertSame(200, $payload['max_output_tokens']);
                return new MockResponse(json_encode([
                    'id' => 'test', 'model' => 'test', 'status' => $responseCount === 1 ? 'completed' : 'incomplete',
                    'incomplete_details' => $responseCount === 1 ? null : ['reason' => 'max_output_tokens'],
                    'output' => [['type' => 'message', 'id' => 'message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Complete response.']]]],
                ], JSON_THROW_ON_ERROR), ['response_headers' => ['Content-Type: application/json']]);
            });
            $container->set('wizhippo_ibexa_content_assistant.http_client', $client);
            $assistant = $container->get(ContentAssistantService::class);
            self::assertSame('Complete response.', $assistant->suggest('rewrite', '', 'Source', 'eng-GB'));
            // This fits by itself but exceeds the budget once the system prompt is added.
            try {
                $assistant->suggest('rewrite', '', str_repeat('s', 9000), 'eng-GB');
                self::fail('The full prompt must be checked before invoking the provider.');
            } catch (ContextWindowExceeded) {
            }
            self::assertSame(1, $client->getRequestsCount());
            $this->expectException(\UnexpectedValueException::class);
            $assistant->suggest('rewrite', '', 'Source', 'eng-GB');
        } finally {
            $kernel->shutdown();
        }
    }
}
