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

    public function testRewriteExplicitlyRequiresEveryTrackingLinkOccurrence(): void
    {
        $url = 'https://example.com/redirect?target=https%3A%2F%2Fsupplier.example&tracking=abc';
        $link = '<link xmlns:xlink="http://www.w3.org/1999/xlink" xlink:href="'.htmlspecialchars($url, ENT_XML1).'">Supplier</link>';
        $xml = self::xml('<para>'.$link.' and '.$link.'</para>');
        $agent = $this->createMock(AgentInterface::class);
        $agent->expects(self::once())->method('call')->with(self::callback(static function (MessageBag $messages) use ($url): bool {
            $items = iterator_to_array($messages);
            $payload = json_decode($items[0]->asText(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([['url' => $url, 'occurrences' => 2]], $payload['required_links']);
            self::assertStringContainsString('tracking', $payload['schema']['link_rules']);

            return true;
        }))->willReturn(new Execution(static function () use ($xml): \Generator {
            yield new Result(new TextResult($xml));
        }));
        $result = $this->service($agent)->suggest('rewrite', 'Restructure supplier information.', $xml, 'eng-GB', self::CAPABILITIES);
        self::assertSame(2, substr_count($result['editXhtml'], 'tracking=abc'));
        $roundTrip = self::getContainer()->get(IbexaRichTextProcessor::class)->read($result['editXhtml']);
        foreach ($roundTrip->getElementsByTagName('link') as $element) {
            self::assertSame($url, $element->getAttributeNS(IbexaRichTextProcessor::XLINK, 'href'));
        }
    }

    /** @dataProvider appendOperations */
    public function testAppendDoesNotRequireRepeatingExistingLinks(string $operation): void
    {
        $agent = $this->createMock(AgentInterface::class);
        $agent->expects(self::once())->method('call')->with(self::callback(static function (MessageBag $messages): bool {
            $items = iterator_to_array($messages);
            $payload = json_decode($items[0]->asText(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([], $payload['required_links']);

            return true;
        }))->willReturn(new Execution(static function (): \Generator {
            yield new Result(new TextResult(self::xml('<para>New content.</para>')));
        }));
        $url = 'https://example.com/redirect?target=https%3A%2F%2Fsupplier.example&tracking=abc';
        $source = self::xml('<para><link xmlns:xlink="http://www.w3.org/1999/xlink" xlink:href="'.htmlspecialchars($url, ENT_XML1).'">Supplier</link></para>');
        $result = $this->service($agent)->suggest($operation, 'Add another section.', $source, 'eng-GB', self::CAPABILITIES);
        self::assertSame(1, substr_count($result['editXhtml'], 'tracking=abc'));
        self::assertStringContainsString('New content.', $result['previewHtml']);
    }

    public static function appendOperations(): iterable
    {
        yield 'author' => ['author'];
        yield 'continue' => ['continue'];
    }

    /** @dataProvider invalidLinkRewrites */
    public function testChangedLinksHaveSpecificSafeErrorAndNoPreview(string $body): void
    {
        $link = self::trackingLink();
        $agent = $this->createMock(AgentInterface::class);
        $agent->expects(self::exactly(2))->method('call')->willReturn(new Execution(static function () use ($body): \Generator {
            yield new Result(new TextResult(self::xml('<para>'.$body.'</para>')));
        }));
        try {
            $this->service($agent)->suggest('rewrite', '', self::xml('<para>'.$link.' and '.$link.'</para>'), 'eng-GB', self::CAPABILITIES);
            self::fail('A link preservation failure must not produce a preview.');
        } catch (InvalidRichTextSuggestion $exception) {
            self::assertSame('The suggestion removed or changed an existing hyperlink. Every original link, including duplicate and tracking links, must be preserved. Your field has not changed. Please regenerate.', $exception->getMessage());
            self::assertStringNotContainsString('example.com', (string)$exception);
            self::assertStringNotContainsString('tracking=abc', (string)$exception);
            self::assertNull($exception->getPrevious());
        }
    }

    public static function invalidLinkRewrites(): iterable
    {
        $link = self::trackingLink();
        yield 'removed' => ['Supplier'];
        yield 'dropped duplicate' => [$link];
        yield 'added duplicate' => [$link.$link.$link];
        yield 'changed tracking parameter' => [str_replace('tracking=abc', 'tracking=changed', $link.$link)];
        yield 'decoded percent encoding' => [str_replace('https%3A%2F%2F', 'https://', $link.$link)];
        yield 'double XML escaping' => [str_replace('&amp;', '&amp;amp;', $link.$link)];
    }

    private static function trackingLink(): string
    {
        return '<link xmlns:xlink="http://www.w3.org/1999/xlink" xlink:href="https://example.com/redirect?target=https%3A%2F%2Fsupplier.example&amp;tracking=abc">Supplier</link>';
    }

    public function testRequiredLinksExcludeProtectedBlocksAndRestoreTheirLinksUnchanged(): void
    {
        $hidden = '<eztemplate name="worldia_sso"><ezcontent><para>'.str_replace('tracking=abc', 'tracking=private', self::trackingLink()).self::trackingLink().'</para></ezcontent></eztemplate>';
        $sourceXml = self::xml('<para>'.self::trackingLink().'</para>'.$hidden);
        $agent = $this->createMock(AgentInterface::class);
        $agent->expects(self::once())->method('call')->willReturnCallback(static function (MessageBag $messages): Execution {
            $payload = json_decode(iterator_to_array($messages)[0]->asText(), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame([['url' => 'https://example.com/redirect?target=https%3A%2F%2Fsupplier.example&tracking=abc', 'occurrences' => 1]], $payload['required_links']);
            self::assertStringNotContainsString('tracking=private', json_encode($payload, JSON_THROW_ON_ERROR));

            return new Execution(static function () use ($payload): \Generator {
                yield new Result(new TextResult($payload['source_docbook']));
            });
        });
        $result = $this->service($agent)->suggest('rewrite', '', $sourceXml, 'eng-GB', self::CAPABILITIES);
        $processor = self::getContainer()->get(IbexaRichTextProcessor::class);
        $source = $processor->read($sourceXml);
        // The native editor conversion adds namespace declarations and link defaults.
        $source = $processor->read($processor->render($source)['editXhtml']);
        $restored = $processor->read($result['editXhtml']);
        self::assertSame($source->getElementsByTagName('eztemplate')->item(0)->C14N(), $restored->getElementsByTagName('eztemplate')->item(0)->C14N());
    }

    private function service(AgentInterface $agent): RichTextContentAssistantService
    {
        return new RichTextContentAssistantService(self::getContainer()->get(IbexaRichTextProcessor::class), new ContentAssistantService($agent), $agent);
    }
}
