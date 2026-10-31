<?php

declare(strict_types=1);

namespace Wizhippo\IbexaContentAssistant\Tests\Integration;

use Symfony\AI\Agent\AgentInterface;
use Symfony\AI\Agent\Execution\Execution;
use Symfony\AI\Agent\Execution\Update\Result;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Result\MultiPartResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\Result\ThinkingResult;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Wizhippo\IbexaContentAssistant\Exception\InvalidRichTextSuggestion;
use Wizhippo\IbexaContentAssistant\Service\ContentAssistantService;
use Wizhippo\IbexaContentAssistant\Service\IbexaRichTextProcessor;
use Wizhippo\IbexaContentAssistant\Service\RichTextContentAssistantService;

final class RichTextContentAssistantServiceTest extends KernelTestCase
{
    private const array CAPABILITIES = ['headingLevels' => [1, 2, 3], 'tables' => true, 'lists' => true];

    private static function xml(string $body): string
    {
        return '<section xmlns="http://docbook.org/ns/docbook" xmlns:ezxhtml="http://ibexa.co/xmlns/dxp/docbook/xhtml" version="5.0-variant ezpublish-1.0">'.$body.'</section>';
    }

    public function testSchemaAwareRewriteCreatesFormattedPreviewFromMultipartResult(): void
    {
        $agent = $this->createMock(AgentInterface::class);
        $agent->expects(self::once())->method('call')->with(self::callback(static function (MessageBag $messages): bool {
            $items = iterator_to_array($messages);
            self::assertInstanceOf(UserMessage::class, $items[0]);
            $content = $items[0]->getContent()[0];
            self::assertInstanceOf(Text::class, $content);
            $payload = json_decode($content->getText(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('ibexa_docbook', $payload['response_format']);
            self::assertSame([1, 2, 3], $payload['schema']['heading_levels']);
            self::assertTrue($payload['schema']['tables']);
            self::assertStringContainsString('<para>Monday: San Juan</para>', $payload['source_docbook']);

            return true;
        }))->willReturn(new Execution(static function (): \Generator {
            yield new Result(new MultiPartResult([
                new ThinkingResult(),
                new TextResult(self::xml('<title ezxhtml:level="2">Itinerary</title><informaltable border="1"><tbody><tr><td><para>Monday</para></td><td><para>San Juan</para></td></tr></tbody></informaltable>')),
            ]));
        }));
        $service = $this->service($agent);
        $result = $service->suggest('rewrite', 'Use headings and a table.', self::xml('<para>Monday: San Juan</para>'), 'eng-GB', self::CAPABILITIES);
        self::assertStringContainsString('<h2', $result['previewHtml']);
        self::assertStringContainsString('<table', $result['previewHtml']);
        self::assertStringContainsString('<h2', $result['editXhtml']);
    }

    public function testArticleAuthoringIncludesExistingContentInCompletePreview(): void
    {
        $agent = $this->createMock(AgentInterface::class);
        $agent->method('call')->willReturn(new Execution(static function (): \Generator {
            yield new Result(new TextResult(self::xml('<title ezxhtml:level="2">New article</title><para>New content.</para>')));
        }));
        $result = $this->service($agent)->suggest('author', 'Write another section.', self::xml('<para>Existing body.</para>'), 'eng-GB', self::CAPABILITIES);
        self::assertStringContainsString('Existing body.', $result['previewHtml']);
        self::assertStringContainsString('New content.', $result['previewHtml']);
        self::assertSame(1, substr_count($result['editXhtml'], 'Existing body.'));
    }

    public function testInvalidRichTextNeverBecomesAPreview(): void
    {
        $agent = $this->createMock(AgentInterface::class);
        $agent->method('call')->willReturn(new Execution(static function (): \Generator {
            yield new Result(new TextResult('<h2>Unvalidated HTML</h2>'));
        }));
        $this->expectException(InvalidRichTextSuggestion::class);
        $this->service($agent)->suggest('rewrite', '', self::xml('<para>Body.</para>'), 'eng-GB', self::CAPABILITIES);
    }

    public function testInvalidSourceIsRejectedBeforeCallingTheModel(): void
    {
        $agent = $this->createMock(AgentInterface::class);
        $agent->expects(self::never())->method('call');
        $this->expectException(\InvalidArgumentException::class);
        $this->service($agent)->suggest('rewrite', '', '<!DOCTYPE section SYSTEM "file:///etc/passwd">'.self::xml('<para>Body</para>'), 'eng-GB', self::CAPABILITIES);
    }

    public function testCompleteRichTextIsNotLimitedToPlainTextFieldLength(): void
    {
        $agent = $this->createMock(AgentInterface::class);
        $agent->expects(self::never())->method('call');
        $this->service($agent)->validate('rewrite', '', self::xml('<para>'.str_repeat('Content ', 2000).'</para>'), 'eng-GB', self::CAPABILITIES);
        $this->service($agent)->validate('rewrite', '', self::xml('<ezembed xmlns:xlink="http://www.w3.org/1999/xlink" xlink:href="ezcontent://123" view="embed"/>'), 'eng-GB', self::CAPABILITIES);
        self::assertTrue(true);
    }

    private function service(AgentInterface $agent): RichTextContentAssistantService
    {
        return new RichTextContentAssistantService(self::getContainer()->get(IbexaRichTextProcessor::class), new ContentAssistantService($agent), $agent);
    }
}
