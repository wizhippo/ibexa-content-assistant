<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Tests\Controller;

use Ibexa\Contracts\Core\Repository\ContentService;
use Ibexa\Contracts\Core\Repository\ContentTypeService;
use Ibexa\Contracts\Core\Repository\LanguageService;
use Ibexa\Contracts\Core\Repository\PermissionResolver;
use Ibexa\Contracts\Core\Repository\Repository;
use Ibexa\Contracts\Core\Repository\SettingService;
use Ibexa\Contracts\Core\Repository\Values\Content\Content;
use Ibexa\Contracts\Core\Repository\Values\Content\ContentInfo;
use Ibexa\Contracts\Core\Repository\Values\Content\Language;
use Ibexa\Contracts\Core\Repository\Values\Setting\Setting;
use Ibexa\Contracts\Core\Repository\Values\User\UserReference;
use Ibexa\Contracts\FieldTypeRichText\RichText\Converter;
use Ibexa\Contracts\FieldTypeRichText\RichText\InputHandlerInterface;
use Ibexa\Contracts\FieldTypeRichText\RichText\ValidatorInterface;
use Ibexa\Core\Repository\Values\Content\VersionInfo;
use Ibexa\Core\Repository\Values\ContentType\ContentType;
use Ibexa\Core\Repository\Values\ContentType\FieldDefinition;
use Ibexa\Core\Repository\Values\ContentType\FieldDefinitionCollection;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\Update\Result;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\RateLimiter\Storage\InMemoryStorage;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Wizhippo\IbexaContentAssistant\Controller\ContentAssistantController;
use Wizhippo\IbexaContentAssistant\Security\ContentAssistantPolicy;
use Wizhippo\IbexaContentAssistant\Service\ContentAssistantAccess;
use Wizhippo\IbexaContentAssistant\Service\ContentAssistantActionRegistry;
use Wizhippo\IbexaContentAssistant\Service\ContentAssistantService;
use Wizhippo\IbexaContentAssistant\Service\IbexaRichTextProcessor;
use Wizhippo\IbexaContentAssistant\Service\RichTextContentAssistantService;

final class ContentAssistantControllerTest extends TestCase
{
    /** @dataProvider rejectedRequests */
    public function testRejectsInvalidRequestsWithoutCallingAI(array $overrides, bool $validCsrf, int $status): void
    {
        [$controller, $arguments, $agent] = $this->fixture($validCsrf);
        $agent->expects(self::never())->method('call');

        self::assertSame($status, $controller->suggest($this->request($overrides), ...$arguments)->getStatusCode());
    }

    public static function rejectedRequests(): iterable
    {
        yield 'invalid csrf' => [[], false, 403];
        yield 'invalid identity' => [['contentId' => '12'], true, 400];
        yield 'unknown field' => [['field' => 'credentials'], true, 403];
        yield 'disabled configuration' => [['action' => 'disabled'], true, 422];
        yield 'unknown configuration' => [['action' => 'missing'], true, 422];
        yield 'invalid configuration' => [['action' => []], true, 422];
        yield 'unsupported operation' => [['operation' => 'publish'], true, 422];
        yield 'no instructions' => [['instructions' => ''], true, 422];
        yield 'too much source' => [['source' => str_repeat('a', 12001)], true, 422];
        yield 'invalid segments' => [['segments' => 'invalid'], true, 400];
        yield 'outdated segments' => [['segments' => ['Text']], true, 400];
        yield 'invalid rich text' => [['richText' => 12], true, 400];
    }

    public function testReturnsSuggestionAndEnforcesRateLimitWithoutSaving(): void
    {
        [$controller, $arguments, $agent] = $this->fixture();
        $agent->expects(self::once())->method('call')->willReturn(new Execution(static function (): \Generator {
            yield new Result(new TextResult('Welcome to TravelOnly.'));
        }));

        $response = $controller->suggest($this->request(), ...$arguments);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame(['suggestion' => 'Welcome to TravelOnly.'], json_decode($response->getContent(), true));
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        self::assertSame(429, $controller->suggest($this->request(), ...$arguments)->getStatusCode());
    }

    public function testServerResolvesTheConfiguredActionInsteadOfTrustingTheOperation(): void
    {
        [$controller, $arguments, $agent] = $this->fixture();
        $agent->expects(self::once())->method('call')->with(self::callback(static function ($messages): bool {
            $items = iterator_to_array($messages);
            $payload = json_decode($items[0]->getContent()[0]->getText(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('rewrite', $payload['operation']);
            self::assertSame('Improve content.', $payload['action_context']['configuration']['prompt']);
            self::assertSame('eng-GB', $payload['action_context']['runtime']['language']);

            return true;
        }))->willReturn(new Execution(static function (): \Generator {
            yield new Result(new TextResult('Rewritten body.'));
        }));
        self::assertSame(200, $controller->suggest($this->request([
            'action' => 'rewrite', 'operation' => 'author', 'source' => 'Original body.',
        ]), ...$arguments)->getStatusCode());
    }

    public function testProviderFailureDoesNotExposeExceptionText(): void
    {
        [$controller, $arguments, $agent] = $this->fixture();
        $agent->method('call')->willThrowException(new \InvalidArgumentException('secret provider payload'));

        $response = $controller->suggest($this->request(), ...$arguments);
        self::assertSame(502, $response->getStatusCode());
        self::assertStringNotContainsString('secret', $response->getContent());
    }

    public function testReturnsWholeFieldRichTextSuggestions(): void
    {
        [$controller, $arguments, $agent] = $this->fixture(true, 'ibexa_richtext');
        $agent->expects(self::once())->method('call')->willReturn(new Execution(static function (): \Generator {
            yield new Result(new TextResult(self::richText('<title ezxhtml:level="2">New heading</title><para>New body</para>')));
        }));
        $response = $controller->suggest($this->request([
            'operation' => 'rewrite', 'richText' => self::richText('<para>Old body</para>'), 'editorCapabilities' => ['headingLevels' => [1, 2, 3], 'tables' => true, 'lists' => true],
        ]), ...$arguments);

        self::assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getContent(), true);
        self::assertStringContainsString('New heading', $data['editXhtml']);
        self::assertStringContainsString('New body', $data['previewHtml']);
    }

    public function testProviderAuthenticationFailureExplainsConfigurationWithoutExposingCredentials(): void
    {
        [$controller, $arguments, $agent] = $this->fixture();
        $agent->method('call')->willThrowException(new AuthenticationException('Incorrect API key: secret-provider-value'));

        $response = $controller->suggest($this->request(), ...$arguments);
        self::assertSame(502, $response->getStatusCode());
        self::assertStringContainsString('OPENAI_API_KEY', $response->getContent());
        self::assertStringNotContainsString('secret-provider-value', $response->getContent());
    }

    private static function richText(string $body): string
    {
        return '<section xmlns="http://docbook.org/ns/docbook" xmlns:ezxhtml="http://ibexa.co/xmlns/dxp/docbook/xhtml" version="5.0-variant ezpublish-1.0">'.$body.'</section>';
    }

    private function richTextAssistant(AgentInterface $agent): RichTextContentAssistantService
    {
        $input = $this->createMock(InputHandlerInterface::class);
        $input->method('fromString')->willReturnCallback(static function ($xml): \DOMDocument {
            $document = new \DOMDocument();
            $document->loadXML($xml);

            return $document;
        });
        $validator = $this->createMock(ValidatorInterface::class);
        $validator->method('validateDocument')->willReturn([]);
        $converter = $this->createMock(Converter::class);
        $converter->method('convert')->willReturnCallback(static fn ($document) => $document);

        return new RichTextContentAssistantService(new IbexaRichTextProcessor($input, $validator, $converter, $converter), new ContentAssistantService($agent), $agent);
    }

    private function request(array $overrides = []): Request
    {
        return Request::create('/content-assistant/suggest', 'POST', [], [], [], [
            'HTTP_X_CSRF_TOKEN' => 'token',
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($overrides + [
            'contentId' => 12,
            'versionNo' => 2,
            'field' => 'body',
            'language' => 'eng-GB',
            'operation' => 'author',
            'instructions' => 'Write a welcome.',
            'source' => '',
        ], JSON_THROW_ON_ERROR));
    }

    private function fixture(bool $validCsrf = true, string $fieldType = 'ibexa_text'): array
    {
        $repository = $this->createMock(Repository::class);
        $resolver = $this->createMock(PermissionResolver::class);
        $resolver->method('canUser')->willReturn(true);
        $user = $this->createMock(UserReference::class);
        $user->method('getUserId')->willReturn(42);
        $resolver->method('getCurrentUserReference')->willReturn($user);
        $repository->method('getPermissionResolver')->willReturn($resolver);

        $content = $this->createMock(Content::class);
        $contentInfo = new ContentInfo(['mainLanguageCode' => 'eng-GB', 'contentTypeId' => 3]);
        $content->method('getContentInfo')->willReturn($contentInfo);
        $content->method('getVersionInfo')->willReturn(new VersionInfo([
            'contentInfo' => $contentInfo,
        ]));
        $contentService = $this->createMock(ContentService::class);
        $contentService->method('loadContent')->with(12, null, 2)->willReturn($content);
        $contentService->expects(self::never())->method('updateContent');
        $contentService->expects(self::never())->method('publishVersion');
        $repository->method('getContentService')->willReturn($contentService);
        $typeService = $this->createMock(ContentTypeService::class);
        $typeService->method('loadContentType')->willReturn(new ContentType([
            'identifier' => 'article',
            'fieldDefinitions' => new FieldDefinitionCollection([
                new FieldDefinition(['identifier' => 'body', 'fieldTypeIdentifier' => $fieldType, 'isTranslatable' => true]),
            ]),
        ]));
        $repository->method('getContentTypeService')->willReturn($typeService);
        $languages = $this->createMock(LanguageService::class);
        $languages->method('loadLanguage')->willReturn(new Language(['languageCode' => 'eng-GB']));
        $repository->method('getContentLanguageService')->willReturn($languages);

        $security = $this->createMock(Security::class);
        $security->method('isGranted')->willReturn(true);
        $checker = $this->createMock(AuthorizationCheckerInterface::class);
        $checker->method('isGranted')->willReturn(true);
        $csrf = $this->createMock(CsrfTokenManagerInterface::class);
        $csrf->method('isTokenValid')->willReturn($validCsrf);
        $container = new Container();
        $container->set('security.csrf.token_manager', $csrf);
        $controller = new ContentAssistantController();
        $controller->setContainer($container);
        $agent = $this->createMock(AgentInterface::class);

        $settings = $this->createMock(SettingService::class);
        $settings->method('loadSetting')->willReturn(new Setting(['value' => [
            'author' => ['name' => 'Write', 'type' => 'author', 'enabled' => true, 'prompt' => 'Write content.'],
            'rewrite' => ['name' => 'Rewrite', 'type' => 'rewrite', 'enabled' => true, 'prompt' => 'Improve content.'],
            'disabled' => ['name' => 'Disabled', 'type' => 'rewrite', 'enabled' => false, 'prompt' => 'Improve content.'],
        ]]));

        return [$controller, [
            new ContentAssistantAccess($repository, $security, new ContentAssistantPolicy($checker), true, ['article' => ['body']]),
            new ContentAssistantService($agent),
            $this->richTextAssistant($agent),
            $repository,
            $this->createMock(LoggerInterface::class),
            new RateLimiterFactory(['id' => 'assistant', 'policy' => 'fixed_window', 'limit' => 1, 'interval' => '1 minute'], new InMemoryStorage()),
            new ContentAssistantActionRegistry($settings, [], sys_get_temp_dir()),
        ], $agent];
    }
}
