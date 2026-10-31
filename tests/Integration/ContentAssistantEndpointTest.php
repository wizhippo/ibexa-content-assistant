<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Tests\Integration;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Wizhippo\IbexaContentAssistant\Service\ContentAssistantService;

final class ContentAssistantEndpointTest extends KernelTestCase
{
    /** @dataProvider endpoints */
    public function testConfiguredEndpointReceivesTheAuthenticatedRequest(string $baseUrl, string $model, string $expectedUrl): void
    {
        $previous = [];
        foreach (['OPENAI_BASE_URL' => $baseUrl, 'OPENAI_API_KEY' => 'test-provider-key', 'WIZHIPPO_IBEXA_CONTENT_ASSISTANT_MODEL' => $model] as $name => $value) {
            $previous[$name] = [$_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            $_ENV[$name] = $_SERVER[$name] = $value;
        }
        try {
            $client = new MockHttpClient(static function (string $method, string $url, array $options) use ($expectedUrl, $model): MockResponse {
                self::assertSame('POST', $method);
                self::assertSame($expectedUrl, $url);
                self::assertSame(['Authorization: Bearer test-provider-key'], $options['normalized_headers']['authorization']);
                $payload = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);
                self::assertSame($model, $payload['model']);
                self::assertSame(8000, $payload['max_output_tokens']);
                self::assertArrayHasKey('input', $payload);

                return new MockResponse(json_encode([
                    'id' => 'test-response', 'model' => $model, 'status' => 'completed',
                    'output' => [['type' => 'message', 'id' => 'test-message', 'role' => 'assistant', 'content' => [['type' => 'output_text', 'text' => 'Rewritten content.']]]],
                ], JSON_THROW_ON_ERROR), ['response_headers' => ['Content-Type: application/json']]);
            });
            self::getContainer()->set('wizhippo_ibexa_content_assistant.http_client', $client);
            $assistant = self::getContainer()->get(ContentAssistantService::class);
            self::assertSame('Rewritten content.', $assistant->suggest('rewrite', '', 'Original content.', 'eng-GB'));
            self::assertSame(1, $client->getRequestsCount());
        } finally {
            self::ensureKernelShutdown();
            foreach ($previous as $name => [$env, $server]) {
                if ($env === null) {
                    unset($_ENV[$name]);
                } else {
                    $_ENV[$name] = $env;
                }
                if ($server === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $server;
                }
            }
        }
    }

    public static function endpoints(): iterable
    {
        yield 'OpenAI default' => ['https://api.openai.com/v1', 'gpt-6.1-terra', 'https://api.openai.com/v1/responses'];
        yield 'OpenRouter including trailing slash' => ['https://openrouter.ai/api/v1/', 'openai/gpt-4.1-mini', 'https://openrouter.ai/api/v1/responses'];
    }
}
